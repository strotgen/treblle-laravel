<?php

declare(strict_types=1);

namespace Treblle\Middlewares;

use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TreblleMiddleware
{
    protected $payload;

    public function __construct()
    {
        $this->payload = [
            'api_key' => config('treblle.api_key'),
            'project_id' => config('treblle.project_id'),
            'version' => 0.9,
            'sdk' => 'laravel',
            'data' => [
                'server' => [
                    'ip' => null,
                    'timezone' => config('app.timezone'),
                    'os' => [
                        'name' => php_uname('s'),
                        'release' => php_uname('r'),
                        'architecture' => php_uname('m'),
                    ],
                    'software' => null,
                    'signature' => null,
                    'protocol' => null,
                    'encoding' => null,
                ],
                'language' => [
                    'name' => 'php',
                    'version' => PHP_VERSION,
                    'expose_php' => $this->getPHPConfigValue('expose_php'),
                    'display_errors' => $this->getPHPConfigValue('display_errors'),
                ],
                'request' => [
                    'timestamp' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
                    'ip' => null,
                    'url' => null,
                    'user_agent' => null,
                    'method' => null,
                    'headers' => '',
                    'body' => '',
                ],
                'response' => [
                    'headers' => '',
                    'code' => null,
                    'size' => 0,
                    'load_time' => 0,
                    'body' => '',
                ],
                'errors' => [],
            ],
        ];
    }

    public function handle($request, Closure $next)
    {
        $response = $next($request);

        /*
         * The terminate method is automatically called when the server supports the FastCGI protocol.
         * In the case the server does not support it, we fall back to manually calling the terminate method.
         *
         * @see https://laravel.com/docs/middleware#terminable-middleware
         */
        if (! str_contains(PHP_SAPI, 'fcgi') && ! $this->httpServerIsOctane()) {
            $this->terminate($request, $response);
        }

        return $response;
    }

    public function terminate($request, $response)
    {
        if (! config('treblle.api_key') && config('treblle.project_id')) {
            return;
        }

        if (config('treblle.ignored_environments')) {
            if (in_array(config('app.env'), explode(',', config('treblle.ignored_environments')))) {
                return;
            }
        }

        $this->payload['data']['server']['ip'] = $request->server('SERVER_ADDR');
        $this->payload['data']['server']['software'] = $request->server('SERVER_SOFTWARE');
        $this->payload['data']['server']['signature'] = $request->server('SERVER_SIGNATURE');
        $this->payload['data']['server']['protocol'] = $request->server('SERVER_PROTOCOL');
        $this->payload['data']['server']['encoding'] = $request->server('HTTP_ACCEPT_ENCODING');

        $this->payload['data']['request']['user_agent'] = $request->server('HTTP_USER_AGENT');
        $this->payload['data']['request']['ip'] = $request->ip();
        $this->payload['data']['request']['url'] = $request->url();
        $this->payload['data']['request']['method'] = $request->method();

        $this->payload['data']['response']['load_time'] = $this->getLoadTime();
        $this->payload['data']['request']['body'] = $this->maskFields($request->request->all());

        $this->payload['data']['request']['headers'] = $this->maskFields(
            collect($request->headers->all())->transform(function ($item) {
                return $item[0];
            })
            ->toArray()
        );

        $this->payload['data']['response']['code'] = $response->status();

        $this->payload['data']['response']['headers'] = $this->maskFields(
            collect($response->headers->all())->transform(function ($item) {
                return $item[0];
            })
            ->toArray()
        );

        if (empty($response->exception)) {
            $this->payload['data']['response']['body'] = json_decode($response->content());
            $this->payload['data']['response']['size'] = strlen($response->content());
        } else {
            array_push(
                $this->payload['data']['errors'],
                [
                    'source' => 'onException',
                    'type' => 'UNHANDLED_EXCEPTION',
                    'message' => $response->exception->getMessage(),
                    'file' => $response->exception->getFile(),
                    'line' => $response->exception->getLine(),
                ]
            );
        }

        Http::withHeaders([
            'x-api-key' => config('treblle.api_key'),
        ])
        ->timeout(2)
        ->post('https://rocknrolla.treblle.com', $this->payload);
    }

    public function getLoadTime(): float
    {
        if ($this->httpServerIsOctane()) {
            return (float) microtime(true) - (float) Cache::store('octane')->get('treblle_start');
        }

        if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            return (float) microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
        }

        return 0.0000;
    }

    public function maskFields($data): array
    {
        if (! is_array($data)) {
            return [];
        }

        // PROVIDE A FALLBACK IN CASE SOMEONE FORGOT TO CLEAR CACHE
        $fields = config(
            'treblle.masked_fields',
            [
                'password',
                'pwd',
                'secret',
                'password_confirmation',
                'cc',
                'card_number',
                'ccv',
                'ssn',
                'credit_score',
                'api_key',
            ]
        );

        if (!empty($fields)) {
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $this->maskFields($value);
                } else {
                    foreach ($fields as $field) {
                        if (preg_match('/\b'.$field.'\b/mi', (string) $key)) {
                            if (strtolower($field) === 'authorization') {
                                $authStringParts = explode(' ', $value);

                                if (count($authStringParts) > 1) {
                                    if (in_array(strtolower($authStringParts[0]), ['basic', 'bearer', 'negotiate'])) {
                                        $data[$key] = $authStringParts[0].' '.str_repeat('*', strlen($authStringParts[1]));
                                    }
                                }
                            } else {
                                $data[$key] = $value != null
                                    ? str_repeat('*', strlen($value))
                                    : $value;
                            }
                        }
                    }
                }
            }
        }

        return $data;
    }

    public function getPHPConfigValue($variable): string
    {
        $isBooleanValue = filter_var(ini_get($variable), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if (is_bool($isBooleanValue)) {
            return ini_get($variable) ? 'On' : 'Off';
        }

        return ini_get($variable);
    }

    public function getResponseHeaders(): array
    {
        $data = [];
        $headers = headers_list();

        if (is_array($headers) && ! empty($headers)) {
            foreach ($headers as $header) {
                $header = explode(':', $header);
                $data[array_shift($header)] = trim(implode(':', $header));
            }
        }

        return $data;
    }

    /**
     * Determine if server is running Octane
     */
    private function httpServerIsOctane(): bool
    {
        return isset($_ENV['OCTANE_DATABASE_SESSION_TTL']);
    }
}
