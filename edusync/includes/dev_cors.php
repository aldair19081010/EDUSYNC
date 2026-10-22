<?php
/**
 * CORS de desarrollo para Flutter Web.
 *
 * La app Web usa http://localhost:<puerto> y Apache/XAMPP se consulta
 * mediante una IP LAN. Nunca autorizar orígenes externos ni producción.
 * La autorización de usuario, colegio, SID y CSRF sigue en cada endpoint.
 */
if (!function_exists('edusync_local_web_cors')) {
    function edusync_local_web_cors(): void {
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') return;

        // Flutter Web/Chrome: puerto efímero localhost / loopback.
        if (!preg_match('~^http://(?:localhost|127\.0\.0\.1):([0-9]{1,5})$~D', $origin, $match)) {
            return;
        }
        $port = (int)$match[1];
        if ($port < 1 || $port > 65535) return;

        // Ambos extremos deben ser locales. Esto impide que un servidor
        // público (por ejemplo edusync.site) active CORS para localhost.
        $serverIp = (string)($_SERVER['SERVER_ADDR'] ?? '');
        $host = strtolower((string)(parse_url(
            'http://' . (string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST
        ) ?: ''));
        $privateIp = static function (string $ip): bool {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
            return filter_var(
                $ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        };
        $localHost = $host === 'localhost' || $privateIp($host);
        $localServer = $privateIp($serverIp) || $serverIp === '::1';
        if (!$localHost || !$localServer) return;

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin', false);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-Session-Id');
        header('Access-Control-Expose-Headers: X-EduSync-Request-ID');
        header('Access-Control-Max-Age: 600');

        // Un preflight no debe crear sesión, consultar MySQL ni requerir SID.
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
edusync_local_web_cors();
