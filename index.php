<?php
declare(strict_types=1);

// ─── Config ───────────────────────────────────────────────────────────────────
define('IKAS_STORE',         'gizemakardesign');
define('IKAS_CLIENT_ID',     '9b683b4a-c376-4986-aefd-d8c72f64cc0e');
define('IKAS_CLIENT_SECRET', 's_mcOfwgAgUPDxnS7tfeBrVtEa9d16457e814b45efbf55103c395b41be');
define('ADMIN_SECRET',       'Cem2026SuperSecretSyncKey');
define('DATABASE_URL',       getenv('DATABASE_URL') ?: '');

header('Access-Control-Allow-Origin: https://gizemakardesign.com');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, x-admin-key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

// ─── Database ─────────────────────────────────────────────────────────────────
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $url    = DATABASE_URL;
    $parsed = parse_url($url);
    $dsn    = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
        $parsed['host'],
        $parsed['port'] ?? 5432,
        ltrim($parsed['path'] ?? '', '/')
    );

    $pdo = new PDO($dsn, $parsed['user'] ?? '', $parsed['pass'] ?? '', [
        PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

// ─── ikas Auth ────────────────────────────────────────────────────────────────
function getIkasToken(): string
{
    $url  = 'https://' . IKAS_STORE . '.myikas.com/api/admin/oauth/token';
    $body = http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => IKAS_CLIENT_ID,
        'client_secret' => IKAS_CLIENT_SECRET,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $res  = curl_exec($ch);
    $data = json_decode($res, true);

    if (empty($data['access_token'])) {
        jsonError('ikas token alınamadı', 500);
    }

    return $data['access_token'];
}

// ─── ikas GraphQL ─────────────────────────────────────────────────────────────
function ikasQuery(string $query, array $variables = []): array
{
    $token = getIkasToken();

    $payload = ['query' => $query];
    if ($variables) {
        $payload['variables'] = $variables;
    }

    $ch = curl_init('https://api.myikas.com/api/v1/admin/graphql');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);

    $res = curl_exec($ch);

    return json_decode($res, true) ?? [];
}

// ─── Points Logic ─────────────────────────────────────────────────────────────
function calcPoints(float $orderTotal): int
{
    return (int) floor($orderTotal / 100) * 5;
}

function processOrder(string $orderId, string $customerId, float $orderTotal, string $paymentStatus, string $orderStatus): void
{
    $pdo = db();

    // EARN — ödendi ve daha önce işlenmedi
    if ($paymentStatus === 'PAID') {
        $stmt = $pdo->prepare('SELECT 1 FROM orders_sync WHERE order_id = $1');
        $stmt->execute([$orderId]);

        if (!$stmt->fetch()) {
            $points = calcPoints($orderTotal);
            if ($points > 0) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO orders_sync (order_id) VALUES ($1)')->execute([$orderId]);
                    $pdo->prepare(
                        'INSERT INTO loyalty_transactions (customer_id, order_id, type, points, order_total, description)
                         VALUES ($1, $2, $3, $4, $5, $6)'
                    )->execute([$customerId, $orderId, 'EARN', $points, $orderTotal, 'Sipariş puan kazanımı']);
                    $pdo->prepare(
                        'INSERT INTO loyalty_wallets (customer_id, points_balance, updated_at)
                         VALUES ($1, $2, NOW())
                         ON CONFLICT (customer_id)
                         DO UPDATE SET points_balance = loyalty_wallets.points_balance + $2, updated_at = NOW()'
                    )->execute([$customerId, $points]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
        }
    }

    // REFUND — iptal/iade edildi, daha önce EARN kaydı var ama REFUND yok
    if (in_array($orderStatus, ['CANCELLED', 'REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
        $stmt = $pdo->prepare('SELECT 1 FROM orders_sync WHERE order_id = $1');
        $stmt->execute([$orderId]);

        if ($stmt->fetch()) {
            $stmt2 = $pdo->prepare("SELECT 1 FROM loyalty_transactions WHERE order_id = \$1 AND type = 'REFUND'");
            $stmt2->execute([$orderId]);

            if (!$stmt2->fetch()) {
                $stmt3 = $pdo->prepare("SELECT points FROM loyalty_transactions WHERE order_id = \$1 AND type = 'EARN'");
                $stmt3->execute([$orderId]);
                $row = $stmt3->fetch();

                if ($row) {
                    $points = (int) $row['points'];
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare(
                            'INSERT INTO loyalty_transactions (customer_id, order_id, type, points, order_total, description)
                             VALUES ($1, $2, $3, $4, $5, $6)'
                        )->execute([$customerId, $orderId, 'REFUND', -$points, $orderTotal, 'İptal/iade puan iadesi']);
                        $pdo->prepare(
                            'UPDATE loyalty_wallets SET points_balance = points_balance - $1, updated_at = NOW()
                             WHERE customer_id = $2'
                        )->execute([$points, $customerId]);
                        $pdo->commit();
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                }
            }
        }
    }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function jsonOut(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $msg, int $status = 400): never
{
    jsonOut(['error' => $msg], $status);
}

function requireAdmin(): void
{
    $key = $_GET['key'] ?? getallheaders()['x-admin-key'] ?? '';
    if ($key !== ADMIN_SECRET) {
        jsonError('Yetkisiz erişim', 403);
    }
}

function generateUuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function buildCampaignInput(string $title, int $amount): array
{
    $now = (int) (microtime(true) * 1000);
    return [
        'id'                           => generateUuid(),
        'createdAt'                    => $now,
        'updatedAt'                    => $now,
        'deleted'                      => false,
        'applicableCustomerGroupIds'   => null,
        'applicableCustomerIds'        => null,
        'applicableCustomerSegmentIds' => null,
        'applicablePrice'              => 'SELL_PRICE',
        'applyCampaignToProductPrice'  => null,
        'buyXThenGetY'                 => null,
        'canCombineWithOtherCampaigns' => false,
        'couponAutoAddProduct'         => null,
        'couponPrefix'                 => null,
        'couponValidityPeriod'         => null,
        'createdFor'                   => null,
        'currencyCodes'                => null,
        'dateRange'                    => null,
        'fixedDiscount'                => [
            'amount'                   => $amount,
            'filters'                  => null,
            'isApplyByCartAmount'      => null,
            'lineItemQuantityRange'    => null,
            'priceRange'               => null,
            'shouldMatchAllConditions' => null,
        ],
        'hasCoupon'                    => true,
        'includeDiscountedProducts'    => false,
        'isFreeShipping'               => null,
        'onlyUseCustomer'              => null,
        'salesChannelIds'              => null,
        'tieredDiscount'               => null,
        'title'                        => $title,
        'translations'                 => [],
        'type'                         => 'FIXED_AMOUNT',
        'usageLimit'                   => null,
        'usageLimitPerCustomer'        => null,
    ];
}

// ─── Routing ──────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$path   = strtok($_SERVER['REQUEST_URI'], '?');
$parts  = explode('/', trim($path, '/'));

// GET /
if ($method === 'GET' && $path === '/') {
    jsonOut(['ok' => true, 'message' => 'ikas loyalty php backend']);
}

// GET /ikas-test
if ($method === 'GET' && $path === '/ikas-test') {
    jsonOut(ikasQuery('query { me { id } }'));
}

// POST /webhook  — ikas order event'lerini işler
if ($method === 'POST' && $path === '/webhook') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // ikas payload yapısı dokümante değil — birkaç olası format deneniyor
    $order = $body['data']['order']
          ?? $body['data']
          ?? $body['order']
          ?? $body;

    $orderId       = $order['id']                 ?? null;
    $customerId    = $order['customer']['id']      ?? null;
    $orderTotal    = (float) ($order['totalPrice'] ?? 0);
    $paymentStatus = $order['orderPaymentStatus']  ?? '';
    $orderStatus   = $order['status']              ?? '';

    if (!$orderId || !$customerId) {
        jsonOut(['ok' => true, 'skipped' => 'gerekli alanlar eksik']);
    }

    processOrder($orderId, $customerId, $orderTotal, $paymentStatus, $orderStatus);
    jsonOut(['ok' => true, 'orderId' => $orderId]);
}

// GET /sync-orders  (admin) — tüm siparişleri ikas'tan çekip işler
if ($method === 'GET' && $path === '/sync-orders') {
    requireAdmin();

    $data   = ikasQuery('{ listOrder { data { id totalPrice customer { id } orderPaymentStatus status } } }');
    $orders = $data['data']['listOrder']['data'] ?? [];

    $counts = ['earn' => 0, 'refund' => 0, 'skipped' => 0];
    foreach ($orders as $order) {
        $orderId       = $order['id']                ?? null;
        $customerId    = $order['customer']['id']    ?? null;
        $orderTotal    = (float) ($order['totalPrice'] ?? 0);
        $paymentStatus = $order['orderPaymentStatus'] ?? '';
        $orderStatus   = $order['status']             ?? '';

        if (!$orderId || !$customerId) { $counts['skipped']++; continue; }

        $before = db()->prepare('SELECT points_balance FROM loyalty_wallets WHERE customer_id = $1');
        $before->execute([$customerId]);
        $balanceBefore = (int) ($before->fetchColumn() ?: 0);

        processOrder($orderId, $customerId, $orderTotal, $paymentStatus, $orderStatus);

        $after = db()->prepare('SELECT points_balance FROM loyalty_wallets WHERE customer_id = $1');
        $after->execute([$customerId]);
        $balanceAfter = (int) ($after->fetchColumn() ?: 0);

        if ($balanceAfter > $balanceBefore)      $counts['earn']++;
        elseif ($balanceAfter < $balanceBefore)  $counts['refund']++;
        else                                     $counts['skipped']++;
    }

    jsonOut(['ok' => true, 'total' => count($orders), 'counts' => $counts]);
}

// GET /loyalty/:customerId
if ($method === 'GET' && $parts[0] === 'loyalty' && !empty($parts[1])) {
    $customerId = $parts[1];
    $stmt = db()->prepare('SELECT points_balance, updated_at FROM loyalty_wallets WHERE customer_id = $1');
    $stmt->execute([$customerId]);
    $wallet = $stmt->fetch() ?: ['points_balance' => 0, 'updated_at' => null];
    jsonOut(['ok' => true, 'customerId' => $customerId, 'wallet' => $wallet]);
}

// GET /ikas-orders  (admin)
if ($method === 'GET' && $path === '/ikas-orders') {
    requireAdmin();
    jsonOut(ikasQuery('{ listOrder { data { id totalPrice customer { id email firstName lastName } orderedAt orderNumber orderPaymentStatus status } } }'));
}

// GET /ikas-customer?email=...  (admin)
if ($method === 'GET' && $path === '/ikas-customer') {
    requireAdmin();
    $email = $_GET['email'] ?? '';
    if (!$email) jsonError('email parametresi gerekli');
    jsonOut(ikasQuery('{ listCustomer(filter: { email: { eq: "' . addslashes($email) . '" } }) { data { id firstName lastName email phone orderCount } } }'));
}

// POST /earn  (admin) — müşteriye manuel puan ver
if ($method === 'POST' && $path === '/earn') {
    requireAdmin();
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $customerId = $body['customerId'] ?? '';
    $points     = (int) ($body['points'] ?? 0);
    $desc       = $body['description'] ?? 'Manuel puan tanımlaması';

    if (!$customerId || $points <= 0) jsonError('customerId ve pozitif points gerekli');

    db()->prepare(
        'INSERT INTO loyalty_transactions (customer_id, order_id, type, points, order_total, description)
         VALUES ($1, NULL, $2, $3, 0, $4)'
    )->execute([$customerId, 'EARN', $points, $desc]);

    db()->prepare(
        'INSERT INTO loyalty_wallets (customer_id, points_balance, updated_at) VALUES ($1, $2, NOW())
         ON CONFLICT (customer_id)
         DO UPDATE SET points_balance = loyalty_wallets.points_balance + $2, updated_at = NOW()'
    )->execute([$customerId, $points]);

    jsonOut(['ok' => true, 'customerId' => $customerId, 'points' => $points]);
}

// GET /create-coupon?code=XXX&amount=5  (admin)
if ($method === 'GET' && $path === '/create-coupon') {
    requireAdmin();

    $code   = $_GET['code']   ?? ('LOYALTY' . strtoupper(substr(md5(uniqid()), 0, 6)));
    $amount = (int) ($_GET['amount'] ?? 5);

    $data = ikasQuery(
        'mutation saveCampaign($input: CampaignInput!) { saveCampaign(input: $input) { id } }',
        ['input' => buildCampaignInput($code, $amount)]
    );

    jsonOut($data);
}

// POST /redeem  — müşteri puanını kupona çevirir (100 puan = 5 TL)
if ($method === 'POST' && $path === '/redeem') {
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $customerId = $body['customerId'] ?? '';

    if (!$customerId) jsonError('customerId gerekli');

    $code = 'LOYALTY' . strtoupper(substr(md5($customerId . microtime()), 0, 7));

    $data = ikasQuery(
        'mutation saveCampaign($input: CampaignInput!) { saveCampaign(input: $input) { id } }',
        ['input' => buildCampaignInput($code, 5)]
    );

    if (!empty($data['errors'])) {
        jsonError('Kupon oluşturulamadı: ' . ($data['errors'][0]['message'] ?? 'bilinmeyen hata'), 500);
    }

    $campaignId = $data['data']['saveCampaign']['id'] ?? null;
    if (!$campaignId) jsonError('Kampanya ID alınamadı', 500);

    $couponData = ikasQuery(
        'mutation campaignAddCoupons($input: AddCouponsInput!) { campaignAddCoupons(input: $input) { id } }',
        [
            'input' => [
                'campaignId'      => $campaignId,
                'generateCoupons' => null,
                'coupons'         => [[
                    'id'                           => null,
                    'code'                         => $code,
                    'usageLimit'                   => 1,
                    'usageLimitPerCustomer'        => null,
                    'applicableCustomerId'         => null,
                    'canCombineWithOtherCampaigns' => false,
                ]],
            ],
        ]
    );

    if (!empty($couponData['errors'])) {
        jsonError('Kupon kodu eklenemedi: ' . ($couponData['errors'][0]['message'] ?? 'bilinmeyen hata'), 500);
    }

    jsonOut(['ok' => true, 'code' => $code]);
}


jsonError('Bulunamadı', 404);
