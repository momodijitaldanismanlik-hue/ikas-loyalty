<?php
declare(strict_types=1);

// ─── Config ───────────────────────────────────────────────────────────────────
define('IKAS_STORE',         'gizemakardesign');
define('IKAS_CLIENT_ID',     '9b683b4a-c376-4986-aefd-d8c72f64cc0e');
define('IKAS_CLIENT_SECRET', 's_mcOfwgAgUPDxnS7tfeBrVtEa9d16457e814b45efbf55103c395b41be');
define('ADMIN_SECRET',       'Cem2026SuperSecretSyncKey');

header('Access-Control-Allow-Origin: https://gizemakardesign.com');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, x-admin-key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

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
    if (!ADMIN_SECRET) {
        jsonError('ADMIN_SECRET tanımlı değil', 500);
    }

    $key = $_GET['key'] ?? getallheaders()['x-admin-key'] ?? '';

    if ($key !== ADMIN_SECRET) {
        jsonError('Yetkisiz erişim', 403);
    }
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
    $data = ikasQuery('query { me { id } }');
    jsonOut($data);
}

// GET /ikas-orders  (admin)
if ($method === 'GET' && $path === '/ikas-orders') {
    requireAdmin();

    $data = ikasQuery('
        {
            listOrder {
                data {
                    id
                    totalPrice
                    customer { id email firstName lastName }
                    orderedAt
                    orderNumber
                    orderPaymentStatus
                    status
                }
            }
        }
    ');

    jsonOut($data);
}

// GET /ikas-customer?email=...  (admin)
if ($method === 'GET' && $path === '/ikas-customer') {
    requireAdmin();

    $email = $_GET['email'] ?? '';
    if (!$email) {
        jsonError('email parametresi gerekli');
    }

    $data = ikasQuery('
        {
            listCustomer(filter: { email: { eq: "' . addslashes($email) . '" } }) {
                data {
                    id
                    firstName
                    lastName
                    email
                    phone
                    orderCount
                }
            }
        }
    ');

    jsonOut($data);
}

// GET /create-coupon?code=XXX&amount=5  (admin)
if ($method === 'GET' && $path === '/create-coupon') {
    requireAdmin();

    $code   = $_GET['code']   ?? ('LOYALTY' . strtoupper(substr(md5(uniqid()), 0, 6)));
    $amount = (int) ($_GET['amount'] ?? 5);

    $data = ikasQuery(
        'mutation saveCampaign($input: CampaignInput!) {
            saveCampaign(input: $input) { id }
        }',
        ['input' => buildCampaignInput($code, $amount)]
    );

    jsonOut($data);
}

// GET /loyalty/:customerId
if ($method === 'GET' && $parts[0] === 'loyalty' && !empty($parts[1])) {
    $customerId = $parts[1];
    jsonOut(['ok' => true, 'customerId' => $customerId, 'wallet' => ['points_balance' => 0]]);
}

// POST /redeem  — müşteri puanını kupona çevirir (100 puan = 5 TL)
if ($method === 'POST' && $path === '/redeem') {
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $customerId = $body['customerId'] ?? '';

    if (!$customerId) {
        jsonError('customerId gerekli');
    }

    $code = 'LOYALTY' . strtoupper(substr(md5($customerId . microtime()), 0, 7));

    $data = ikasQuery(
        'mutation saveCampaign($input: CampaignInput!) {
            saveCampaign(input: $input) { id }
        }',
        ['input' => buildCampaignInput($code, 5)]
    );

    if (!empty($data['errors'])) {
        jsonError('Kupon oluşturulamadı: ' . ($data['errors'][0]['message'] ?? 'bilinmeyen hata'), 500);
    }

    jsonOut(['ok' => true, 'code' => $code]);
}


jsonError('Bulunamadı', 404);