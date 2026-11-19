<?php
/**
 * Opt-in request timing (diagnostics only).
 *
 * Enabled only when the environment variable APP_TIMING=1, and then only
 * for the allowlisted routes below. Each measured request writes ONE line
 * to the server log when it finishes, using a fixed route name, e.g.:
 *
 *   TravelEase timing route=customer_dashboard status=200
 *     db_connect=180ms db_setup=140ms db_prepare=6/420ms db_execute=6/430ms
 *     db_other=0/0ms db_total=1170ms total=1210ms
 *
 * prepare and execute are separate because real prepared statements cost
 * one round trip each. Logged fields contain no paths, user IDs, emails,
 * query strings or SQL text. With APP_TIMING unset, nothing here runs.
 */

/** METHOD + path => fixed route name. Only these requests are measured. */
const REQUEST_TIMING_ROUTES = [
    'POST /auth/login.php'                 => 'login_post',
    'GET /includes/redirect_dashboard.php' => 'dashboard_redirect',
    'GET /customer/dashboard.php'          => 'customer_dashboard',
    'GET /customer/trips/create.php'       => 'plan_trip',
    'GET /customer/trips/view.php'         => 'trip_view',
];

/** The fixed route name for this request, or null if it is not measured. */
function request_timing_route(): ?string
{
    if (getenv('APP_TIMING') !== '1') {
        return null;
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $key = ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . (is_string($path) ? $path : '');
    return REQUEST_TIMING_ROUTES[$key] ?? null;
}

function request_timing_enabled(): bool
{
    return request_timing_route() !== null;
}

final class RequestTiming
{
    public static float $connect = 0.0;
    public static float $setup = 0.0;
    public static array $counts = ['prepare' => 0, 'execute' => 0, 'other' => 0];
    public static array $times = ['prepare' => 0.0, 'execute' => 0.0, 'other' => 0.0];
    private static bool $registered = false;

    public static function add(string $kind, float $seconds): void
    {
        self::$counts[$kind]++;
        self::$times[$kind] += $seconds;
    }

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        register_shutdown_function([self::class, 'log']);
    }

    public static function log(): void
    {
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        $ms = fn(float $s): int => (int) round($s * 1000);
        $dbTotal = self::$connect + self::$setup + array_sum(self::$times);
        error_log(sprintf(
            'TravelEase timing route=%s status=%d db_connect=%dms db_setup=%dms '
            . 'db_prepare=%d/%dms db_execute=%d/%dms db_other=%d/%dms db_total=%dms total=%dms',
            request_timing_route() ?? 'unknown', http_response_code() ?: 0,
            $ms(self::$connect), $ms(self::$setup),
            self::$counts['prepare'], $ms(self::$times['prepare']),
            self::$counts['execute'], $ms(self::$times['execute']),
            self::$counts['other'], $ms(self::$times['other']),
            $ms($dbTotal), $ms(microtime(true) - $start)
        ));
    }
}

/** PDO that times prepare(), query() and exec() round trips. */
class TimedPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $t = microtime(true);
        try {
            return parent::prepare($query, $options);
        } finally {
            RequestTiming::add('prepare', microtime(true) - $t);
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $t = microtime(true);
        try {
            return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        } finally {
            RequestTiming::add('other', microtime(true) - $t);
        }
    }

    public function exec(string $statement): int|false
    {
        $t = microtime(true);
        try {
            return parent::exec($statement);
        } finally {
            RequestTiming::add('other', microtime(true) - $t);
        }
    }
}

/** Statement class that times execute() round trips. */
class TimedPDOStatement extends PDOStatement
{
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        $t = microtime(true);
        try {
            return parent::execute($params);
        } finally {
            RequestTiming::add('execute', microtime(true) - $t);
        }
    }
}
