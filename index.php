<?php
/**
 * =============================================================================
 *  Mountain元 · 站内积分系统（单文件版 v2，开源版）
 * =============================================================================
 *  一个文件搞定全部：注册登录、3D 账户卡、签到答题、用户名/编号转账、
 *  6 位转账密码、实时汇率换算、管理后台、移动端/桌面端自适应。
 *
 *  部署：把本文件命名为 index.php 上传到网站根目录，浏览器打开首页，
 *        第一次访问会进入「初始化安装」页面，由你创建管理员账号。
 *  数据：首次运行自动创建 data/ 目录，数据存放在 data/mountain.json.php
 *        （用 .php 结尾 + 保护头，即使被猜到地址也下载不到内容）。
 *
 *  环境：PHP 7.4 / 8.x，无需数据库、无需任何扩展、无外部 CDN 依赖。
 *
 *  免责声明：本项目仅供学习、研究与自用部署。站内积分、汇率均为演示数据，
 *  不构成任何投资、兑换或收益承诺；请勿用于以虚拟货币、积分兑换等名义
 *  向他人收取资金的活动。
 * =============================================================================
 */

/* ============================================================================
 * 1. 配置区（想改什么改这里）
 * ==========================================================================*/

const APP_NAME          = 'Mountain元 · 站内积分系统';
const APP_SHORT         = 'MTD';
const APP_TAGLINE       = '站内娱乐积分｜注册即送 5.000 MTD';
const CONTACT_QQ        = '';   // 联系方式，留空 = 页面不展示（例如 '123456789'）

const DATA_DIR_NAME     = 'data';
const STORE_FILE_NAME   = 'mountain.json.php';  // 正式数据文件（.php 结尾 = 防下载）
const LEGACY_FILE_NAME  = 'mountain.json';      // 旧版数据文件，导入后自动清空

const UNIT              = 1000;                 // 1 MTD = 1000 内部单位（账面 3 位小数）
const INITIAL_POOL      = 10000 * UNIT;         // 初始发行量：10000 MTD
const REGISTER_BONUS    = 5 * UNIT;             // 注册赠送：5 MTD
const DAILY_BONUS       = 100;                  // 每日签到：0.1 MTD
const QUIZ_BONUS        = 100;                  // 每题奖励：0.1 MTD
const QUIZ_DAILY_LIMIT  = 100;                  // 每人每日答题上限（0 = 不限）
const TRANSFER_FEE_RATE = 0.05;                 // 转账手续费 5%
const TRANSFER_FEE_MIN  = 50 * UNIT;            // 单笔超过 50 MTD 才收手续费

const PIN_MAX_FAIL      = 5;                    // 转账密码连续错误上限
const PIN_LOCK_SEC      = 300;                  // 超出上限后锁定 5 分钟

const PER_PAGE          = 20;                   // 账单每页条数

/*
 * 管理员账号不再硬编码在代码里。
 * 首次访问站点时会进入「初始化安装」页面，由部署者自己创建管理员
 * （见下方 setup 动作），创建完成后安装入口永久失效。
 */

const DEBUG             = false;                // 排查问题时改成 true 可显示报错详情

/* ============================================================================
 * 2. 运行环境
 * ==========================================================================*/

if (DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

if (session_status() === PHP_SESSION_NONE) {
    $httpsOn = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $httpsOn,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $httpsOn, true);
    }

    session_start();
}

$dataDir    = __DIR__ . DIRECTORY_SEPARATOR . DATA_DIR_NAME;
$dataFile  = $dataDir . DIRECTORY_SEPARATOR . STORE_FILE_NAME;
$legacyFile = $dataDir . DIRECTORY_SEPARATOR . LEGACY_FILE_NAME;
$lockFile   = $dataDir . DIRECTORY_SEPARATOR . 'mountain.lock';

/* ============================================================================
 * 3. 数据层
 * ==========================================================================*/

/** 汇率默认值：1 单位外币 = 多少人民币 */
function defaultRates(): array
{
    return [
        'CNY' => 1.0,
        'USD' => 6.74,
        'HKD' => 0.865,
        'EUR' => 7.80,
        'SGD' => 7.35,
        'AUD' => 6.75,
        'CAD' => 4.65,
        'KRW' => 0.00072,
    ];
}

/** 货币中文名 */
function currencyLabels(): array
{
    return [
        'CNY' => '人民币',
        'USD' => '美元',
        'HKD' => '港币',
        'EUR' => '欧元',
        'SGD' => '新加坡元',
        'AUD' => '澳元',
        'CAD' => '加元',
        'KRW' => '韩元',
        'MTD' => 'Mountain元',
    ];
}

/** 货币符号 / 单位 */
function currencyUnits(): array
{
    return [
        'CNY' => '元',
        'USD' => '美元',
        'HKD' => '港币',
        'EUR' => '欧元',
        'SGD' => '新元',
        'AUD' => '澳元',
        'CAD' => '加元',
        'KRW' => '韩元',
        'MTD' => 'MTD',
    ];
}

/** 从 settings 里取出有效汇率表（非法值自动回退默认） */
function resolveRates(array $settings): array
{
    $rates = defaultRates();
    $saved = isset($settings['rates']) && is_array($settings['rates']) ? $settings['rates'] : [];

    foreach ($saved as $code => $value) {
        $code = strtoupper((string)$code);

        if (isset($rates[$code]) && is_numeric($value) && (float)$value > 0) {
            $rates[$code] = (float)$value;
        }
    }

    return $rates;
}

/** 1 元人民币 = 多少 MTD */
function resolveMtdPerCny(array $settings): float
{
    $value = $settings['mtd_per_cny'] ?? 1000;

    return (is_numeric($value) && (float)$value > 0) ? (float)$value : 1000.0;
}

/** 把数据补全成完整结构（兼容旧版数据） */
function normalizeData(array $data): array
{
    $settings = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : [];

    if (!isset($settings['pool']) || !is_numeric($settings['pool'])) {
        $settings['pool'] = INITIAL_POOL;
    }

    if (empty($settings['created_at'])) {
        $settings['created_at'] = date('Y-m-d H:i:s');
    }

    $settings['rates']       = resolveRates($settings);
    $settings['mtd_per_cny'] = resolveMtdPerCny($settings);
    $settings['schema']      = 2;

    $users = isset($data['users']) && is_array($data['users']) ? $data['users'] : [];
    $clean = [];

    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }

        $user['id']              = (string)($user['id'] ?? '');
        $user['username']        = (string)($user['username'] ?? '');
        $user['password']        = (string)($user['password'] ?? '');
        $user['role']            = (($user['role'] ?? '') === 'admin') ? 'admin' : 'user';
        $user['balance']         = (int)($user['balance'] ?? 0);
        $user['created_at']      = (string)($user['created_at'] ?? date('Y-m-d H:i:s'));
        $user['last_checkin']    = $user['last_checkin'] ?? null;
        $user['active']          = ($user['active'] ?? true) === true;
        $user['pay_pin']         = (isset($user['pay_pin']) && is_string($user['pay_pin']) && $user['pay_pin'] !== '')
            ? $user['pay_pin'] : null;
        $user['pin_fail']        = (int)($user['pin_fail'] ?? 0);
        $user['pin_locked_until'] = (int)($user['pin_locked_until'] ?? 0);
        $user['last_quiz_date']  = $user['last_quiz_date'] ?? null;
        $user['quiz_count']      = (int)($user['quiz_count'] ?? 0);

        if ($user['id'] === '' || $user['username'] === '') {
            continue;
        }

        $clean[] = $user;
    }

    $transactions = isset($data['transactions']) && is_array($data['transactions'])
        ? array_values(array_filter($data['transactions'], 'is_array'))
        : [];

    return [
        'settings'     => $settings,
        'users'        => $clean,
        'transactions' => $transactions,
    ];
}

/** 生成带保护头的数据文件内容（直接访问会被 PHP 立即 exit，什么都不输出） */
function encodeStore(array $data): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        $json = '{}';
    }

    return "<?php exit; /* MTD 数据文件，请勿直接访问 */ ?>\n" . $json . "\n";
}

/** 解析数据文件内容（自动兼容旧版无保护头的明文 JSON） */
function decodeStore(string $raw): ?array
{
    if (trim($raw) === '') {
        return null;
    }

    if (strncmp(ltrim($raw), '<?php', 5) === 0) {
        $pos = strpos($raw, '?>');

        if ($pos === false) {
            return null;
        }

        $raw = substr($raw, $pos + 2);
    }

    $decoded = json_decode(trim($raw), true);

    return is_array($decoded) ? $decoded : null;
}

function loadData(string $file): array
{
    $raw = @file_get_contents($file);

    if ($raw === false) {
        return normalizeData([]);
    }

    $decoded = decodeStore($raw);

    return normalizeData(is_array($decoded) ? $decoded : []);
}

function saveData(string $file, string $lockFile, array $data): bool
{
    $lock = @fopen($lockFile, 'c');

    if ($lock === false) {
        return false;
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            return false;
        }

        $temp = $file . '.tmp';

        if (@file_put_contents($temp, encodeStore($data), LOCK_EX) === false) {
            return false;
        }

        if (!@rename($temp, $file)) {
            @unlink($temp);

            return false;
        }

        return true;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * 加锁读取 → 回调修改 → 原子写回。
 * 回调返回 ['success' => true, ...] 才会落盘；
 * 返回 ['persist' => true, ...] 时即使失败也落盘（用于记录错误次数等）。
 */
function withLockedData(string $file, string $lockFile, callable $callback): array
{
    $lock = @fopen($lockFile, 'c');

    if ($lock === false || !flock($lock, LOCK_EX)) {
        return [
            'success' => false,
            'message' => '无法锁定数据文件，请检查 data 目录权限。',
        ];
    }

    try {
        $data   = loadData($file);
        $result = $callback($data);

        if (!is_array($result)) {
            return [
                'success' => false,
                'message' => '数据处理失败。',
            ];
        }

        $needSave = (($result['success'] ?? false) === true) || (($result['persist'] ?? false) === true);

        if ($needSave) {
            $temp = $file . '.tmp';

            if (@file_put_contents($temp, encodeStore($data), LOCK_EX) === false) {
                return [
                    'success' => false,
                    'message' => '数据写入失败，请检查 data 目录权限。',
                ];
            }

            if (!@rename($temp, $file)) {
                @unlink($temp);

                return [
                    'success' => false,
                    'message' => '数据保存失败，请检查 data 目录权限。',
                ];
            }
        }

        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/* ============================================================================
 * 4. 首屏初始化：数据目录 / 旧数据迁移 / 管理员账户
 * ==========================================================================*/

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

/* 自保护：数据目录禁止 Web 直接访问（Apache 生效，其它环境无副作用） */
$htaccessFile = $dataDir . DIRECTORY_SEPARATOR . '.htaccess';

if (is_dir($dataDir) && !file_exists($htaccessFile)) {
    @file_put_contents(
        $htaccessFile,
        "# 由 MTD 系统自动生成：禁止通过网址直接访问数据目录\n"
        . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
    );
}

if (!file_exists($dataFile)) {
    $imported = null;

    if (file_exists($legacyFile)) {
        $raw = @file_get_contents($legacyFile);

        if ($raw !== false) {
            $decoded = decodeStore($raw);

            if (is_array($decoded)) {
                $imported = $decoded;
            }
        }

        /* 旧文件是明文 JSON，导入后立即抹掉内容，避免密码哈希继续暴露 */
        @file_put_contents(
            $legacyFile,
            "<?php exit; /* 数据已迁移到 " . STORE_FILE_NAME . "，此文件已作废 */ ?>\n"
        );
    }

    if ($imported === null) {
        $imported = [
            'settings'     => ['pool' => INITIAL_POOL, 'created_at' => date('Y-m-d H:i:s')],
            'users'        => [],
            'transactions' => [],
        ];
    }

    saveData($dataFile, $lockFile, normalizeData($imported));
}

$data = loadData($dataFile);

/* 没有任何管理员时，自动创建（ID 0000） */
$hasAdmin = false;

foreach ($data['users'] as $account) {
    if (($account['role'] ?? '') === 'admin') {
        $hasAdmin = true;
        break;
    }
}

$needsSetup = !$hasAdmin;

if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* ============================================================================
 * 5. 通用工具函数
 * ==========================================================================*/

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf(): string
{
    return h((string)$_SESSION['csrf']);
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf() . '">';
}

function redirect(string $url = ''): void
{
    if ($url === '') {
        $url = 'index.php';
    }

    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

/** 内部单位 → 显示金额（3 位小数） */
function money(int $units): string
{
    return number_format($units / UNIT, 3, '.', '');
}

/** 编号补足 4 位显示：170 → 0170，000 → 0000 */
function pad4(string $id): string
{
    $id = trim($id);

    if (!ctype_digit($id)) {
        return $id;
    }

    return str_pad(substr($id, -4), 4, '0', STR_PAD_LEFT);
}

/** 编号归一化：0000 / 000 / 0 / 0170 / 170 都能对上 */
function idKey(string $id): string
{
    $id = trim($id);

    return ctype_digit($id) ? (string)(int)$id : strtolower($id);
}

function userIndexById(array $users, string $id): int
{
    $key = idKey($id);

    foreach ($users as $index => $user) {
        if (idKey((string)($user['id'] ?? '')) === $key) {
            return $index;
        }
    }

    return -1;
}

function userIndexByUsername(array $users, string $username): int
{
    $username = trim($username);

    if ($username === '') {
        return -1;
    }

    foreach ($users as $index => $user) {
        if (strcasecmp((string)($user['username'] ?? ''), $username) === 0) {
            return $index;
        }
    }

    return -1;
}

/** 按「编号或用户名」查人（转账用），支持 @用户名 */
function findUserIndex(array $users, string $handle): int
{
    $handle = trim($handle);

    if ($handle === '') {
        return -1;
    }

    if (strpos($handle, '@') === 0) {
        $handle = substr($handle, 1);
    }

    if (ctype_digit($handle)) {
        $key = idKey($handle);

        foreach ($users as $index => $user) {
            if (idKey((string)($user['id'] ?? '')) === $key) {
                return $index;
            }
        }
    }

    return userIndexByUsername($users, $handle);
}

/** 生成未被占用的 4 位编号（0001-9999） */
function createUserId(array $users): string
{
    $used = [];

    foreach ($users as $user) {
        $used[idKey((string)($user['id'] ?? ''))] = true;
    }

    $available = [];

    for ($i = 1; $i <= 9999; $i++) {
        if (!isset($used[(string)$i])) {
            $available[] = $i;
        }
    }

    if (empty($available)) {
        return '';
    }

    return str_pad((string)$available[random_int(0, count($available) - 1)], 4, '0', STR_PAD_LEFT);
}

function addTransaction(
    array &$data,
    string $type,
    ?string $fromId,
    ?string $toId,
    int $amount,
    int $fee,
    string $description,
    ?string $operatorId = null
): void {
    $data['transactions'][] = [
        'id'          => bin2hex(random_bytes(8)),
        'type'        => $type,
        'from_id'     => $fromId,
        'to_id'       => $toId,
        'amount'      => $amount,
        'fee'         => $fee,
        'description' => $description,
        'operator_id' => $operatorId,
        'created_at'  => date('Y-m-d H:i:s'),
    ];
}

/** 资金池 / 系统出账账号（管理员）的编号 */
function adminId(array $data): string
{
    foreach ($data['users'] as $user) {
        if (($user['role'] ?? '') === 'admin') {
            return (string)$user['id'];
        }
    }

    return '0000';
}

function txnTypeLabels(): array
{
    return [
        'register_bonus'  => '注册奖励',
        'checkin'         => '每日签到',
        'quiz'            => '答题奖励',
        'transfer'        => '用户转账',
        'admin_add'       => '管理员增加',
        'admin_remove'    => '管理员扣除',
        'account_deleted' => '账户注销',
        'pool_adjust'     => '资金池调整',
    ];
}

function txnLabel(string $type): string
{
    $labels = txnTypeLabels();

    return $labels[$type] ?? $type;
}

/**
 * 联系方式展示文本；未配置 CONTACT_QQ 时返回空串，页面自动隐藏该行。
 */
function contactLine(string $prefix = '联系'): string
{
    $qq = trim((string)CONTACT_QQ);

    return $qq === '' ? '' : ($prefix . ' QQ：' . $qq);
}

function currentUser(array $data): ?array
{
    $id = $_SESSION['user_id'] ?? '';

    if ($id === '' || $id === null) {
        return null;
    }

    $index = userIndexById($data['users'], (string)$id);

    if ($index < 0 || ($data['users'][$index]['active'] ?? false) !== true) {
        unset($_SESSION['user_id']);

        return null;
    }

    return $data['users'][$index];
}

function requirePostCsrf(): void
{
    $sent = (string)($_POST['csrf'] ?? '');

    if (!hash_equals((string)$_SESSION['csrf'], $sent)) {
        http_response_code(403);
        exit('请求验证失败，请返回上一页刷新后重试。');
    }
}

function requireLogin(array $data): array
{
    $user = currentUser($data);

    if ($user === null) {
        flash('error', '请先登录。');
        redirect('index.php?page=login');
    }

    return $user;
}

function requireAdmin(array $data): array
{
    $user = requireLogin($data);

    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('没有权限访问此页面。');
    }

    return $user;
}

/* ---------------------------- 金额 / 密码 工具 ---------------------------- */

/** 解析用户输入的金额 → 内部单位；非法返回 null */
function parseAmount(string $text): ?int
{
    $text = trim(str_replace(['，', ' ', ','], ['', '', '.'], $text));

    if (!preg_match('/^\d{1,12}(\.\d{1,3})?$/', $text)) {
        return null;
    }

    $units = (int)round((float)$text * UNIT);

    return $units > 0 ? $units : null;
}

function isSixDigitPin(string $pin): bool
{
    return (bool)preg_match('/^\d{6}$/', $pin);
}

/** 字符数（主机未装 mbstring 时退化为字节数，不会报错） */
function textLen(string $value): int
{
    return function_exists('mb_strlen') ? (int)mb_strlen($value, 'UTF-8') : strlen($value);
}

/** 按字符截断（主机未装 mbstring 时退化为字节截断） */
function textCut(string $value, int $limit): string
{
    if (function_exists('mb_substr')) {
        return (string)mb_substr($value, 0, $limit, 'UTF-8');
    }

    return substr($value, 0, $limit);
}

/** 清理用户输入的短文本：去掉换行、控制符，限制长度 */
function cleanText(string $value, int $limit = 60): string
{
    $value = str_replace(["\r", "\n", "\t", "\0"], ' ', $value);
    $value = trim(preg_replace('/\s{2,}/u', ' ', $value) ?? $value);

    return textCut($value, $limit);
}

function userHasPin(array $user): bool
{
    return isset($user['pay_pin']) && is_string($user['pay_pin']) && $user['pay_pin'] !== '';
}

/** 转账密码剩余锁定秒数（0 = 未锁定） */
function pinLockRemain(array $user): int
{
    $until = (int)($user['pin_locked_until'] ?? 0);

    return $until > time() ? ($until - time()) : 0;
}

/* ---------------------------- 汇率换算 ---------------------------- */

/** 任意货币金额 → 人民币 */
function toCny(float $amount, string $code, array $rates, float $mtdPerCny): float
{
    if ($code === 'MTD') {
        return $mtdPerCny > 0 ? $amount / $mtdPerCny : 0.0;
    }

    return $amount * (float)($rates[$code] ?? 0.0);
}

/** 任意货币之间换算 */
function convertCurrency(float $amount, string $from, string $to, array $rates, float $mtdPerCny): float
{
    $cny = toCny($amount, $from, $rates, $mtdPerCny);

    if ($to === 'MTD') {
        return $cny * $mtdPerCny;
    }

    $rate = (float)($rates[$to] ?? 0.0);

    return $rate > 0 ? $cny / $rate : 0.0;
}

/** 结果数字格式化（大数不显示多余小数，小数保留合适位数） */
function formatNumber(float $value): string
{
    $abs = abs($value);

    if ($abs >= 1000) {
        return number_format($value, 2, '.', ',');
    }

    if ($abs >= 1) {
        return number_format($value, 3, '.', '');
    }

    if ($abs >= 0.001) {
        return number_format($value, 4, '.', '');
    }

    return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
}

/* ---------------------------- 分页 / 链接 ---------------------------- */

function pageSlice(array $items, int $page, int $perPage): array
{
    $total = count($items);
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;

    return [
        'items'  => array_slice($items, $offset, $perPage),
        'page'   => $page,
        'pages'  => $pages,
        'total'  => $total,
        'offset' => $offset,
    ];
}

/** 生成站内链接：q('page' => 'admin', 'p' => 2) */
function q(array $params): string
{
    $clean = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $clean[$key] = $value;
    }

    return 'index.php?' . http_build_query($clean);
}

function poolOf(array $data): int
{
    return (int)($data['settings']['pool'] ?? 0);
}

function totalUserBalance(array $data): int
{
    $sum = 0;

    foreach ($data['users'] as $user) {
        if (($user['role'] ?? '') === 'admin') {
            continue;
        }

        $sum += (int)($user['balance'] ?? 0);
    }

    return $sum;
}

function countActiveUsers(array $data): int
{
    $count = 0;

    foreach ($data['users'] as $user) {
        if (($user['role'] ?? '') !== 'admin' && ($user['active'] ?? false) === true) {
            $count++;
        }
    }

    return $count;
}

function countTodayCheckins(array $data): int
{
    $today = date('Y-m-d');
    $count = 0;

    foreach ($data['users'] as $user) {
        if (($user['last_checkin'] ?? '') === $today) {
            $count++;
        }
    }

    return $count;
}


/* ============================================================================
 * 6. 请求处理（所有 POST 动作）
 * ==========================================================================*/

/** 生成一道新的乘法题并存入 session（题目只在服务端产生，防止前端伪造） */
function quizNewQuestion(): array
{
    $a = random_int(1, 10);
    $b = random_int(1, 10);

    $_SESSION['quiz_question'] = [
        'q' => $a . ' * ' . $b,
        'answer' => $a * $b,
        'time' => time(),
    ];

    return $_SESSION['quiz_question'];
}

$action = (string)($_POST['action'] ?? '');

/*
 * 初始化安装模式：系统里还没有管理员时，站点只提供「创建管理员」这一步，
 * 其余页面全部挂起（避免没部署完就被别人抢注或把资金池掏空）。
 */
if ($needsSetup) {
    $formError = '';
    $formOld   = ['username' => (string)($_POST['username'] ?? '')];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'setup') {
        requirePostCsrf();

        $username      = trim((string)($_POST['username'] ?? ''));
        $password      = (string)($_POST['password'] ?? '');
        $passwordAgain = (string)($_POST['password_again'] ?? '');

        if (!preg_match('/^[\p{L}\p{N}_-]{2,24}$/u', $username)) {
            $formError = '管理员用户名需为 2-24 位中文、字母、数字、下划线或短横线。';
        } elseif (strlen($password) < 8) {
            $formError = '管理员密码至少 8 位，建议字母 + 数字 + 符号混合。';
        } elseif (strlen($password) > 72) {
            $formError = '管理员密码最长 72 位。';
        } elseif ($password !== $passwordAgain) {
            $formError = '两次输入的密码不一致。';
        } else {
            $created = withLockedData($dataFile, $lockFile, function (array &$newData) use ($username, $password): array {
                foreach ($newData['users'] as $account) {
                    if (($account['role'] ?? '') === 'admin') {
                        return ['success' => false, 'message' => '系统已经存在管理员，安装入口已关闭。'];
                    }
                }

                $newData['users'][] = [
                    'id'               => '0000',
                    'username'         => $username,
                    'password'         => password_hash($password, PASSWORD_DEFAULT),
                    'role'             => 'admin',
                    'balance'          => 0,
                    'created_at'       => date('Y-m-d H:i:s'),
                    'last_checkin'     => null,
                    'active'           => true,
                    'pay_pin'          => null,
                    'pin_fail'         => 0,
                    'pin_locked_until' => 0,
                    'last_quiz_date'   => null,
                    'quiz_count'       => 0,
                ];

                return ['success' => true, 'message' => '管理员创建成功。'];
            });

            if (($created['success'] ?? false) === true) {
                session_regenerate_id(true);
                $_SESSION['csrf']    = bin2hex(random_bytes(32));
                $_SESSION['user_id'] = '0000';

                flash('success', '管理员账户已创建，你已自动登录。建议先到「汇率设置」核对汇率。');
                redirect(q(['page' => 'admin']));
            }

            $formError = (string)($created['message'] ?? '创建失败，请重试。');
        }
    }

    renderSetupPage(getFlash(), $formOld, $formError);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();

    /* 安装入口在完成后即失效 */
    if ($action === 'setup') {
        flash('info', '系统已完成初始化，安装入口已关闭。');
        redirect(q(['page' => 'home']));
    }

    /* ------------------------------- 注册 ------------------------------- */
    if ($action === 'register') {
        $username      = trim((string)($_POST['username'] ?? ''));
        $password      = (string)($_POST['password'] ?? '');
        $passwordAgain = (string)($_POST['password_again'] ?? '');

        if (!preg_match('/^[\p{L}\p{N}_-]{2,24}$/u', $username)) {
            flash('error', '用户名需为 2-24 位中文、字母、数字、下划线或短横线。');
            redirect('index.php?page=register');
        }

        if (strlen($password) < 6 || strlen($password) > 72) {
            flash('error', '密码长度需要为 6-72 位。');
            redirect('index.php?page=register');
        }

        if ($password !== $passwordAgain) {
            flash('error', '两次输入的密码不一致。');
            redirect('index.php?page=register');
        }

        $result = withLockedData($dataFile, $lockFile, function (array &$newData) use ($username, $password): array {
            if (userIndexByUsername($newData['users'], $username) !== -1) {
                return ['success' => false, 'message' => '这个用户名已经存在。'];
            }

            $id = createUserId($newData['users']);

            if ($id === '') {
                return ['success' => false, 'message' => '编号已经分配完了。'];
            }

            $pool = (int)($newData['settings']['pool'] ?? 0);

            if ($pool < REGISTER_BONUS) {
                return ['success' => false, 'message' => '资金池余额不足，暂时无法注册。'];
            }

            $newData['settings']['pool'] = $pool - REGISTER_BONUS;

            $newData['users'][] = [
                'id'               => $id,
                'username'         => $username,
                'password'         => password_hash($password, PASSWORD_DEFAULT),
                'role'             => 'user',
                'balance'          => REGISTER_BONUS,
                'created_at'       => date('Y-m-d H:i:s'),
                'last_checkin'     => null,
                'active'           => true,
                'pay_pin'          => null,
                'pin_fail'         => 0,
                'pin_locked_until' => 0,
                'last_quiz_date'   => null,
                'quiz_count'       => 0,
            ];

            addTransaction(
                $newData,
                'register_bonus',
                adminId($newData),
                $id,
                REGISTER_BONUS,
                0,
                '新用户注册奖励'
            );

            return [
                'success' => true,
                'message' => '注册成功！你的账户编号是 ' . $id . '，已获得 5.000 MTD，去登录吧。',
            ];
        });

        if (($result['success'] ?? false) === true) {
            flash('success', (string)$result['message']);
            redirect('index.php?page=login');
        }

        flash('error', (string)($result['message'] ?? '注册失败。'));
        redirect('index.php?page=register');
    }

    /* ------------------------------- 登录 ------------------------------- */
    if ($action === 'login') {
        $now        = time();
        $blockUntil = (int)($_SESSION['login_block_until'] ?? 0);

        if ($blockUntil > $now) {
            flash('error', '尝试过于频繁，请 ' . ($blockUntil - $now) . ' 秒后再试。');
            redirect('index.php?page=login');
        }

        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $loginUser = null;

        foreach ($data['users'] as $account) {
            if (
                strcasecmp((string)($account['username'] ?? ''), $username) === 0
                && ($account['active'] ?? false) === true
                && password_verify($password, (string)($account['password'] ?? ''))
            ) {
                $loginUser = $account;
                break;
            }
        }

        if ($loginUser === null) {
            $_SESSION['login_fail'] = (int)($_SESSION['login_fail'] ?? 0) + 1;

            if ($_SESSION['login_fail'] >= 5) {
                $_SESSION['login_fail']        = 0;
                $_SESSION['login_block_until'] = $now + 60;
                flash('error', '连续 5 次输错，请 60 秒后再试。');
            } else {
                flash('error', '用户名或密码错误。');
            }

            redirect('index.php?page=login');
        }

        unset($_SESSION['login_fail'], $_SESSION['login_block_until']);

        session_regenerate_id(true);
        $_SESSION['csrf']    = bin2hex(random_bytes(32));
        $_SESSION['user_id'] = (string)$loginUser['id'];

        flash('success', '欢迎回来，' . (string)$loginUser['username'] . '！');

        if (($loginUser['role'] ?? '') === 'admin') {
            redirect('index.php?page=admin');
        }

        redirect('index.php');
    }

    /* ------------------------------- 退出 ------------------------------- */
    if ($action === 'logout') {
        unset($_SESSION['user_id']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        flash('success', '你已安全退出。');
        redirect('index.php');
    }

    $me = currentUser($data);

    /* ------------------------------- 签到 ------------------------------- */
    if ($action === 'checkin') {
        if ($me === null || ($me['role'] ?? '') !== 'user') {
            flash('error', '只有普通用户可以签到。');
            redirect('index.php');
        }

        $today = date('Y-m-d');

        $result = withLockedData($dataFile, $lockFile, function (array &$newData) use ($me, $today): array {
            $index = userIndexById($newData['users'], (string)$me['id']);

            if ($index < 0) {
                return ['success' => false, 'message' => '账户不存在。'];
            }

            if (($newData['users'][$index]['active'] ?? false) !== true) {
                return ['success' => false, 'message' => '账户已停用。'];
            }

            if ((string)($newData['users'][$index]['last_checkin'] ?? '') === $today) {
                return ['success' => false, 'message' => '今天已经签到过了，明天再来。'];
            }

            $pool = (int)($newData['settings']['pool'] ?? 0);

            if ($pool < DAILY_BONUS) {
                return ['success' => false, 'message' => '资金池余额不足，暂时无法发放签到奖励。'];
            }

            $newData['settings']['pool']        = $pool - DAILY_BONUS;
            $newData['users'][$index]['balance'] = (int)$newData['users'][$index]['balance'] + DAILY_BONUS;
            $newData['users'][$index]['last_checkin'] = $today;

            addTransaction(
                $newData,
                'checkin',
                adminId($newData),
                (string)$me['id'],
                DAILY_BONUS,
                0,
                '每日签到奖励'
            );

            return ['success' => true, 'message' => '签到成功，获得 0.100 MTD。'];
        });

        flash(
            ($result['success'] ?? false) ? 'success' : 'error',
            (string)($result['message'] ?? '签到失败。')
        );

        redirect('index.php?page=checkin');
    }

    /* ------------------------------- 转账 ------------------------------- */
    if ($action === 'transfer') {
        if ($me === null || ($me['role'] ?? '') !== 'user') {
            flash('error', '只有普通用户可以转账。');
            redirect('index.php');
        }

        $handle     = trim((string)($_POST['to'] ?? ''));
        $amountText = trim((string)($_POST['amount'] ?? ''));
        $pinInput   = trim((string)($_POST['pay_pin'] ?? ''));
        $note       = cleanText((string)($_POST['note'] ?? ''), 60);

        if ($handle === '') {
            flash('error', '请填写收款人的编号或用户名。');
            redirect('index.php?page=transfer');
        }

        $amount = parseAmount($amountText);

        if ($amount === null) {
            flash('error', '金额格式不正确，最多三位小数（例如 10 或 50.500）。');
            redirect('index.php?page=transfer');
        }

        $result = withLockedData($dataFile, $lockFile, function (array &$newData) use (
            $me,
            $handle,
            $amount,
            $pinInput,
            $note
        ): array {
            $fromIndex   = userIndexById($newData['users'], (string)$me['id']);
            $targetIndex = findUserIndex($newData['users'], $handle);

            if ($fromIndex < 0) {
                return ['success' => false, 'message' => '你的账户不存在。'];
            }

            if ($targetIndex < 0) {
                return ['success' => false, 'message' => '找不到收款人「' . $handle . '」，请核对编号或用户名。'];
            }

            if ($targetIndex === $fromIndex) {
                return ['success' => false, 'message' => '不能给自己转账。'];
            }

            $sender   = $newData['users'][$fromIndex];
            $receiver = $newData['users'][$targetIndex];

            if (($sender['active'] ?? false) !== true || ($receiver['active'] ?? false) !== true) {
                return ['success' => false, 'message' => '收款账户不可用（可能已注销）。'];
            }

            /* --- 转账密码校验 --- */
            if (userHasPin($sender)) {
                $remain = pinLockRemain($sender);

                if ($remain > 0) {
                    return [
                        'success' => false,
                        'message' => '转账密码已锁定，请 ' . $remain . ' 秒后再试。',
                    ];
                }

                if (!isSixDigitPin($pinInput) || !password_verify($pinInput, (string)$sender['pay_pin'])) {
                    $fails = (int)($sender['pin_fail'] ?? 0) + 1;
                    $left  = PIN_MAX_FAIL - $fails;

                    if ($left <= 0) {
                        $newData['users'][$fromIndex]['pin_fail']         = 0;
                        $newData['users'][$fromIndex]['pin_locked_until'] = time() + PIN_LOCK_SEC;

                        return [
                            'success' => false,
                            'message' => '转账密码连续错误 ' . PIN_MAX_FAIL . ' 次，已锁定 '
                                . (int)(PIN_LOCK_SEC / 60) . ' 分钟。',
                            'persist' => true,
                        ];
                    }

                    $newData['users'][$fromIndex]['pin_fail'] = $fails;

                    return [
                        'success' => false,
                        'message' => '转账密码错误，还可以尝试 ' . $left . ' 次。',
                        'persist' => true,
                    ];
                }

                $newData['users'][$fromIndex]['pin_fail']         = 0;
                $newData['users'][$fromIndex]['pin_locked_until'] = 0;
            }

            /* --- 余额与手续费 --- */
            $fee = $amount > TRANSFER_FEE_MIN
                ? (int)ceil($amount * TRANSFER_FEE_RATE)
                : 0;

            $total         = $amount + $fee;
            $senderBalance = (int)$sender['balance'];

            if ($senderBalance < $total) {
                return [
                    'success' => false,
                    'message' => '余额不足：本次需 ' . money($total) . ' MTD'
                        . ($fee > 0 ? '（含手续费 ' . money($fee) . '）' : '')
                        . '，当前余额 ' . money($senderBalance) . ' MTD。',
                ];
            }

            $newData['users'][$fromIndex]['balance'] = $senderBalance - $total;
            $newData['users'][$targetIndex]['balance'] = (int)$receiver['balance'] + $amount;
            $newData['settings']['pool'] = (int)($newData['settings']['pool'] ?? 0) + $fee;

            $description = '转账给 ' . (string)$receiver['username']
                . '（编号 ' . pad4((string)$receiver['id']) . '）';

            if ($note !== '') {
                $description .= '｜备注：' . $note;
            }

            addTransaction(
                $newData,
                'transfer',
                (string)$sender['id'],
                (string)$receiver['id'],
                $amount,
                $fee,
                $description
            );

            return [
                'success' => true,
                'message' => '已转给 ' . (string)$receiver['username'] . ' ' . money($amount) . ' MTD'
                    . ($fee > 0 ? '，手续费 ' . money($fee) . ' MTD' : '，本次免手续费')
                    . '，共扣除 ' . money($total) . ' MTD。',
            ];
        });

        flash(
            ($result['success'] ?? false) ? 'success' : 'error',
            (string)($result['message'] ?? '转账失败。')
        );

        redirect('index.php?page=transfer');
    }

    /* ------------------------------- 答题 ------------------------------- */
    if ($action === 'quiz') {
        if ($me === null || ($me['role'] ?? '') !== 'user') {
            flash('error', '只有普通用户可以答题。');
            redirect('index.php');
        }

        $postedQ = trim((string)($_POST['question'] ?? ''));
        $answer  = trim((string)($_POST['answer'] ?? ''));
        $saved   = $_SESSION['quiz_question'] ?? null;

        if (!is_array($saved) || (string)($saved['q'] ?? '') !== $postedQ) {
            flash('error', '题目已失效，请重新获取题目。');
            redirect('index.php?page=quiz');
        }

        if ((time() - (int)($saved['time'] ?? 0)) > 600) {
            unset($_SESSION['quiz_question']);
            flash('error', '题目已超时（10 分钟），请重新获取。');
            redirect('index.php?page=quiz');
        }

        if (!preg_match('/^\d{1,4}$/', $answer) || (int)$answer !== (int)$saved['answer']) {
            unset($_SESSION['quiz_question']);
            flash('error', '答案不对，换个题目再来。');
            redirect('index.php?page=quiz');
        }

        unset($_SESSION['quiz_question']);

        $today = date('Y-m-d');

        $result = withLockedData($dataFile, $lockFile, function (array &$newData) use ($me, $today): array {
            $index = userIndexById($newData['users'], (string)$me['id']);

            if ($index < 0) {
                return ['success' => false, 'message' => '账户不存在。'];
            }

            if ((string)($newData['users'][$index]['last_quiz_date'] ?? '') !== $today) {
                $newData['users'][$index]['last_quiz_date'] = $today;
                $newData['users'][$index]['quiz_count']     = 0;
            }

            $done = (int)$newData['users'][$index]['quiz_count'];

            if (QUIZ_DAILY_LIMIT > 0 && $done >= QUIZ_DAILY_LIMIT) {
                return [
                    'success' => false,
                    'message' => '今天的答题额度已用完（每日 ' . QUIZ_DAILY_LIMIT . ' 题），明天再来。',
                ];
            }

            $pool = (int)($newData['settings']['pool'] ?? 0);

            if ($pool < QUIZ_BONUS) {
                return ['success' => false, 'message' => '资金池余额不足，暂时无法发放答题奖励。'];
            }

            $newData['settings']['pool'] = $pool - QUIZ_BONUS;
            $newData['users'][$index]['balance']    = (int)$newData['users'][$index]['balance'] + QUIZ_BONUS;
            $newData['users'][$index]['quiz_count'] = $done + 1;

            addTransaction(
                $newData,
                'quiz',
                adminId($newData),
                (string)$me['id'],
                QUIZ_BONUS,
                0,
                '乘法答题奖励'
            );

            $left = QUIZ_DAILY_LIMIT > 0 ? ('，今日还剩 ' . max(0, QUIZ_DAILY_LIMIT - $done - 1) . ' 题') : '';

            return ['success' => true, 'message' => '答对啦，获得 0.100 MTD' . $left . '。'];
        });

        flash(
            ($result['success'] ?? false) ? 'success' : 'error',
            (string)($result['message'] ?? '操作失败。')
        );

        redirect('index.php?page=quiz');
    }

    /* --------------------------- 修改登录密码 --------------------------- */
    if ($action === 'password_change') {
        if ($me === null) {
            flash('error', '请先登录。');
            redirect('index.php?page=login');
        }

        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $again   = (string)($_POST['new_password_again'] ?? '');

        if (!password_verify($current, (string)$me['password'])) {
            flash('error', '当前密码不正确。');
            redirect('index.php?page=security');
        }

        if (strlen($new) < 6 || strlen($new) > 72) {
            flash('error', '新密码长度需要为 6-72 位。');
            redirect('index.php?page=security');
        }

        if ($new !== $again) {
            flash('error', '两次输入的新密码不一致。');
            redirect('index.php?page=security');
        }

        $result = withLockedData($dataFile, $lockFile, function (array &$newData) use ($me, $new): array {
            $index = userIndexById($newData['users'], (string)$me['id']);

            if ($index < 0) {
                return ['success' => false, 'message' => '账户不存在。'];
            }

            $newData['users'][$index]['password'] = password_hash($new, PASSWORD_DEFAULT);

            return ['success' => true, 'message' => '登录密码已更新，请牢记新密码。'];
        });

        flash(
            ($result['success'] ?? false) ? 'success' : 'error',
            (string)($result['message'] ?? '修改失败。')
        );

        redirect('index.php?page=security');
    }

    /* --------------------------- 转账密码设置 --------------------------- */
    if ($action === 'pin_set') {
        if ($me === null) {
            flash('error', '请先登录。');
            redirect('index.php?page=login');
        }

        $mode    = (string)($_POST['mode'] ?? 'set');
        $current = (string)($_POST['current_password'] ?? '');

        if (!password_verify($current, (string)$me['password'])) {
            flash('error', '登录密码不正确，无法修改转账密码。');
            redirect('index.php?page=security');
        }

        $pin = null;

        if ($mode === 'remove') {
            $pin = '';
        } else {
            $pin     = trim((string)($_POST['pin'] ?? ''));
            $pinAgain = trim((string)($_POST['pin_again'] ?? ''));

            if (!isSixDigitPin($pin)) {
                flash('error', '转账密码必须是 6 位数字。');
                redirect('index.php?page=security');
            }

            if ($pin !== $pinAgain) {
                flash('error', '两次输入的转账密码不一致。');
                redirect('index.php?page=security');
            }
        }

        $result = withLockedData($dataFile, $lockFile, function (array &$newData) use ($me, $pin): array {
            $index = userIndexById($newData['users'], (string)$me['id']);

            if ($index < 0) {
                return ['success' => false, 'message' => '账户不存在。'];
            }

            $newData['users'][$index]['pay_pin']          = $pin === '' ? null : password_hash($pin, PASSWORD_DEFAULT);
            $newData['users'][$index]['pin_fail']          = 0;
            $newData['users'][$index]['pin_locked_until']  = 0;

            return [
                'success' => true,
                'message' => $pin === '' ? '已关闭转账密码，之后转账无需验证。' : '转账密码已设置，转账时需要输入 6 位密码。',
            ];
        });

        flash(
            ($result['success'] ?? false) ? 'success' : 'error',
            (string)($result['message'] ?? '设置失败。')
        );

        redirect('index.php?page=security');
    }

    /* --------------------------- 管理员：余额调整 --------------------------- */
    if ($action === 'admin_adjust') {
        if ($me === null || ($me['role'] ?? '') !== 'admin') {
            http_response_code(403);
            exit('没有权限。');
        }

        $targetId   = trim((string)($_POST['target_id'] ?? ''));
        $amountText = trim((string)($_POST['amount'] ?? ''));
        $reason     = cleanText((string)($_POST['reason'] ?? ''), 60);
        $mode       = (string)($_POST['mode'] ?? 'add');

        if (!preg_match('/^\d{1,4}$/', $targetId)) {
            flash('error', '目标编号无效。');
            redirect('index.php?page=admin');
        }

        $amount = parseAmount(ltrim($amountText, '-'));

        if ($amount === null) {
            flash('error', '金额格式无效，最多三位小数。');
            redirect('index.php?page=admin');
        }

        if ($reason === '') {
            $reason = ($mode === 'remove') ? '管理员扣除' : '管理员增加';
        }

        $result = withLockedData($dataFile, $lockFile, function (array &$newData) use ($targetId, $amount, $reason, $mode): array {
            $index = userIndexById($newData['users'], $targetId);

            if ($index < 0 || ($newData['users'][$index]['role'] ?? '') === 'admin') {
                return ['success' => false, 'message' => '目标账户不存在。'];
            }

            $balance = (int)$newData['users'][$index]['balance'];

            if ($mode === 'remove') {
                if ($balance < $amount) {
                    return ['success' => false, 'message' => '该用户余额不足。'];
                }

                $newData['users'][$index]['balance']  = $balance - $amount;
                $newData['settings']['pool'] = (int)($newData['settings']['pool'] ?? 0) + $amount;

                addTransaction(
                    $newData,
                    'admin_remove',
                    (string)$newData['users'][$index]['id'],
                    adminId($newData),
                    $amount,
                    0,
                    $reason,
                    adminId($newData)
                );

                return ['success' => true, 'message' => '已扣除 ' . money($amount) . ' MTD。'];
            }

            $pool = (int)($newData['settings']['pool'] ?? 0);

            if ($pool < $amount) {
                return ['success' => false, 'message' => '资金池余额不足。'];
            }

            $newData['settings']['pool'] = $pool - $amount;
            $newData['users'][$index]['balance'] = $balance + $amount;

            addTransaction(
                $newData,
                'admin_add',
                adminId($newData),
                (string)$newData['users'][$index]['id'],
                $amount,
                0,
                $reason,
                adminId($newData)
            );

            return ['success' => true, 'message' => '已增加 ' . money($amount) . ' MTD。'];
        });

        flash(
            ($result['success'] ?? false) ? 'success' : 'error',
            (string)($result['message'] ?? '调整失败。')
        );

        redirect('index.php?page=admin');
    }

    /* --------------------------- 管理员：资金池调整 --------------------------- */
    if ($action === 'admin_pool') {
        if ($me === null || ($me['role'] ?? '') !== 'admin') {
            http_response_code(403);
            exit('没有权限。');
        }

        $mode   = (string)($_POST['mode'] ?? 'add');
        $amount = parseAmount(ltrim((string)($_POST['amount'] ?? ''), '-'));

        if ($amount === null) {
            flash('error', '金额格式无效。');
            redirect('index.php?page=admin');
        }

        $result = withLockedData($dataFile, $lockFile, function (array &$newData) use ($mode, $amount): array {
            $pool = (int)($newData['settings']['pool'] ?? 0);

            if ($mode === 'remove') {
                if ($pool < $amount) {
                    return ['success' => false, 'message' => '资金池没有这么多余额。'];
                }

                $newData['settings']['pool'] = $pool - $amount;
            } else {
                $newData['settings']['pool'] = $pool + $amount;
            }

            addTransaction(
                $newData,
                'pool_adjust',
                null,
                null,
                $amount,
                0,
                $mode === 'remove' ? '管理员减少资金池' : '管理员补充资金池',
                adminId($newData)
            );

            return [
                'success' => true,
                'message' => '资金池已' . ($mode === 'remove' ? '减少 ' : '增加 ') . money($amount) . ' MTD。',
            ];
        });

        flash(
            ($result['success'] ?? false) ? 'success' : 'error',
            (string)($result['message'] ?? '调整失败。')
        );

        redirect('index.php?page=admin');
    }

    /* --------------------------- 管理员：汇率管理 --------------------------- */
    if ($action === 'admin_rates') {
        if ($me === null || ($me['role'] ?? '') !== 'admin') {
            http_response_code(403);
            exit('没有权限。');
        }

        $posted = isset($_POST['rates']) && is_array($_POST['rates']) ? $_POST['rates'] : [];
        $rates  = defaultRates();
        $bad    = false;

        foreach ($rates as $code => $old) {
            $raw = trim((string)($posted[$code] ?? ''));

            if ($raw === '' || !is_numeric($raw) || (float)$raw <= 0) {
                $bad = true;
                break;
            }

            $rates[$code] = (float)$raw;
        }

        $mtdRaw = trim((string)($_POST['mtd_per_cny'] ?? ''));

        if ($bad || $mtdRaw === '' || !is_numeric($mtdRaw) || (float)$mtdRaw <= 0) {
            flash('error', '汇率必须是大于 0 的数字，请检查后重新提交。');
            redirect('index.php?page=admin&tab=rates');
        }

        $mtdPerCny = (float)$mtdRaw;

        $result = withLockedData($dataFile, $lockFile, function (array &$newData) use ($rates, $mtdPerCny): array {
            $newData['settings']['rates']       = $rates;
            $newData['settings']['mtd_per_cny'] = $mtdPerCny;

            return ['success' => true, 'message' => '汇率已更新，全站换算即时生效。'];
        });

        flash(
            ($result['success'] ?? false) ? 'success' : 'error',
            (string)($result['message'] ?? '更新失败。')
        );

        redirect('index.php?page=admin&tab=rates');
    }

    /* --------------------------- 管理员：重置转账密码 --------------------------- */
    if ($action === 'admin_reset_pin') {
        if ($me === null || ($me['role'] ?? '') !== 'admin') {
            http_response_code(403);
            exit('没有权限。');
        }

        $targetId = trim((string)($_POST['target_id'] ?? ''));

        $result = withLockedData($dataFile, $lockFile, function (array &$newData) use ($targetId): array {
            $index = userIndexById($newData['users'], $targetId);

            if ($index < 0 || ($newData['users'][$index]['role'] ?? '') === 'admin') {
                return ['success' => false, 'message' => '目标账户不存在。'];
            }

            $newData['users'][$index]['pay_pin']          = null;
            $newData['users'][$index]['pin_fail']          = 0;
            $newData['users'][$index]['pin_locked_until']  = 0;

            return ['success' => true, 'message' => '已重置该用户的转账密码，用户可重新设置。'];
        });

        flash(
            ($result['success'] ?? false) ? 'success' : 'error',
            (string)($result['message'] ?? '重置失败。')
        );

        redirect('index.php?page=admin');
    }

    /* --------------------------- 管理员：注销账户 --------------------------- */
    if ($action === 'admin_delete') {
        if ($me === null || ($me['role'] ?? '') !== 'admin') {
            http_response_code(403);
            exit('没有权限。');
        }

        $targetId = trim((string)($_POST['target_id'] ?? ''));

        $result = withLockedData($dataFile, $lockFile, function (array &$newData) use ($targetId): array {
            $index = userIndexById($newData['users'], $targetId);

            if ($index < 0 || ($newData['users'][$index]['role'] ?? '') === 'admin') {
                return ['success' => false, 'message' => '目标账户不存在。'];
            }

            $balance = (int)($newData['users'][$index]['balance'] ?? 0);

            $newData['users'][$index]['active']        = false;
            $newData['users'][$index]['balance']       = 0;
            $newData['users'][$index]['pay_pin']       = null;
            $newData['users'][$index]['deleted_at']    = date('Y-m-d H:i:s');
            $newData['settings']['pool'] = (int)($newData['settings']['pool'] ?? 0) + $balance;

            addTransaction(
                $newData,
                'account_deleted',
                (string)$newData['users'][$index]['id'],
                adminId($newData),
                $balance,
                0,
                '管理员注销账户，余额回收至资金池',
                adminId($newData)
            );

            return ['success' => true, 'message' => '账户已注销，剩余余额已回收到资金池。'];
        });

        flash(
            ($result['success'] ?? false) ? 'success' : 'error',
            (string)($result['message'] ?? '注销失败。')
        );

        redirect('index.php?page=admin');
    }

    /* --------------------------- 管理员：恢复账户 --------------------------- */
    if ($action === 'admin_activate') {
        if ($me === null || ($me['role'] ?? '') !== 'admin') {
            http_response_code(403);
            exit('没有权限。');
        }

        $targetId = trim((string)($_POST['target_id'] ?? ''));

        $result = withLockedData($dataFile, $lockFile, function (array &$newData) use ($targetId): array {
            $index = userIndexById($newData['users'], $targetId);

            if ($index < 0 || ($newData['users'][$index]['role'] ?? '') === 'admin') {
                return ['success' => false, 'message' => '目标账户不存在。'];
            }

            $newData['users'][$index]['active'] = true;
            unset($newData['users'][$index]['deleted_at']);

            return ['success' => true, 'message' => '账户已恢复，可以正常登录。'];
        });

        flash(
            ($result['success'] ?? false) ? 'success' : 'error',
            (string)($result['message'] ?? '恢复失败。')
        );

        redirect('index.php?page=admin');
    }
}


/* ============================================================================
 * 7. 渲染层：图标 / 头部 / 导航 / 底部 / 3D 卡片 / 账单表格
 * ==========================================================================*/

/** 内联 SVG 图标（不依赖任何外部资源） */
function icon(string $name): string
{
    $paths = [
        'checkin'  => '<path d="M7 3v3M17 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/><path d="m9 14 2 2 4-4"/>',
        'quiz'     => '<path d="M12 3v3M12 18v3M4.2 7.5l2.6 1.5M17.2 15l2.6 1.5M4.2 16.5l2.6-1.5M17.2 9l2.6-1.5"/><circle cx="12" cy="12" r="3.2"/>',
        'transfer' => '<path d="M4 8h13l-3-3M20 16H7l3 3"/>',
        'rate'     => '<path d="M4 19V5M4 19h16M8 16V9M12.5 16v-4M17 16V7"/>',
        'card'     => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 10h18M7 15h4"/>',
        'shield'   => '<path d="M12 3l7 3v6c0 4.2-2.9 7.6-7 9-4.1-1.4-7-4.8-7-9V6l7-3Z"/><path d="m9 12 2 2 4-4"/>',
        'user'     => '<circle cx="12" cy="8.5" r="3.6"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
        'logout'   => '<path d="M15 4h3a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-3M10 8l-4 4 4 4M6 12h9"/>',
        'admin'    => '<path d="M12 3l1.9 4.3 4.6.5-3.4 3.1.9 4.6L12 13.3 8 15.5l.9-4.6L5.5 7.8l4.6-.5L12 3Z"/><path d="M5 20h14"/>',
        'home'     => '<path d="M4 11.5 12 4l8 7.5M6.5 10v9.5h11V10"/>',
        'bill'     => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 12h7M9 16h5"/>',
        'gift'     => '<path d="M4 10h16v1a9 9 0 0 1-18 0v-1ZM12 10v11M4 10l1.5-3.5A2 2 0 0 1 8 6.2L12 10M20 10l-1.5-3.5A2 2 0 0 0 16 6.2L12 10"/>',
        'lock'     => '<rect x="5" y="10.5" width="14" height="10" rx="2.4"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/>',
    ];

    $body = $paths[$name] ?? $paths['card'];

    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

/** 品牌图标（小） */
function logoMark(string $gradientId, string $class = 'logo-mark'): string
{
    return '<svg class="' . $class . '" viewBox="0 0 48 48" aria-hidden="true">'
        . '<defs><linearGradient id="' . $gradientId . '" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="#9ec5ff"/><stop offset="0.5" stop-color="#a78bfa"/>'
        . '<stop offset="1" stop-color="#22d3ee"/></linearGradient></defs>'
        . '<path d="M3 38 L17 13.5 L27.5 30 L33.5 21 L45 38 Z" fill="url(#' . $gradientId . ')"/>'
        . '<circle cx="34" cy="10.5" r="4.3" fill="#f5c451"/>'
        . '</svg>';
}

/** 导航项 */
function navItems(?array $user): array
{
    if ($user === null) {
        return [
            ['page' => 'home', 'label' => '首页', 'icon' => 'home'],
            ['page' => 'exchange', 'label' => '汇率换算', 'icon' => 'rate'],
            ['page' => 'login', 'label' => '登录', 'icon' => 'user'],
            ['page' => 'register', 'label' => '注册', 'icon' => 'gift'],
        ];
    }

    if (($user['role'] ?? '') === 'admin') {
        return [
            ['page' => 'admin', 'label' => '管理后台', 'icon' => 'admin', 'tab' => 'users'],
            ['page' => 'admin', 'label' => '汇率设置', 'icon' => 'rate', 'tab' => 'rates'],
            ['page' => 'admin', 'label' => '全站账单', 'icon' => 'bill', 'tab' => 'bills'],
            ['page' => 'security', 'label' => '安全设置', 'icon' => 'lock'],
        ];
    }

    return [
        ['page' => 'home', 'label' => '我的卡片', 'icon' => 'card'],
        ['page' => 'transfer', 'label' => '转账', 'icon' => 'transfer'],
        ['page' => 'checkin', 'label' => '签到', 'icon' => 'checkin'],
        ['page' => 'quiz', 'label' => '答题', 'icon' => 'quiz'],
        ['page' => 'exchange', 'label' => '汇率', 'icon' => 'rate'],
        ['page' => 'bills', 'label' => '账单', 'icon' => 'bill'],
        ['page' => 'security', 'label' => '安全', 'icon' => 'lock'],
    ];
}

function renderFlashBar(?array $flash): void
{
    if ($flash === null) {
        return;
    }

    $type = ($flash['type'] ?? 'info') === 'success' ? 'success' : (($flash['type'] ?? '') === 'error' ? 'error' : 'info');

    echo '<div class="flash flash-' . $type . '" id="flashBar" role="status">'
        . '<span class="flash-dot"></span><span>' . h((string)($flash['message'] ?? '')) . '</span>'
        . '<button type="button" class="flash-x" aria-label="关闭" onclick="this.parentNode.remove()">&times;</button>'
        . '</div>';
}

function renderHead(string $title, ?array $user, ?array $flash, string $page): void
{
    $fullTitle = $title === '' ? APP_NAME : ($title . '｜' . APP_NAME);
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#070b18">
<meta name="format-detection" content="telephone=no">
<title><?= h($fullTitle) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode(logoMark('fav')) ?>">
<style>
:root{
  --bg:#070b18;
  --bg-2:#0b1120;
  --panel:rgba(255,255,255,.045);
  --panel-2:rgba(255,255,255,.07);
  --line:rgba(255,255,255,.10);
  --line-2:rgba(255,255,255,.18);
  --text:#e8ecf7;
  --muted:#9aa6c4;
  --muted-2:#7180a3;
  --indigo:#6366f1;
  --violet:#a78bfa;
  --cyan:#22d3ee;
  --gold:#f5c451;
  --green:#34d399;
  --red:#fb7185;
  --radius:18px;
  --radius-sm:12px;
  --shadow:0 18px 50px rgba(3,7,18,.55);
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
  background:var(--bg);
  color:var(--text);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei","PingFang SC","Hiragino Sans GB",Roboto,"Helvetica Neue",Arial,sans-serif;
  line-height:1.6;
  -webkit-font-smoothing:antialiased;
  overflow-x:hidden;
  min-height:100vh;
}
/* 背景光斑 */
body::before{
  content:"";position:fixed;inset:0;z-index:-2;
  background:
    radial-gradient(50% 40% at 12% 0%, rgba(99,102,241,.32), transparent 70%),
    radial-gradient(45% 45% at 88% 8%, rgba(34,211,238,.20), transparent 70%),
    radial-gradient(60% 50% at 50% 100%, rgba(167,139,250,.16), transparent 70%),
    linear-gradient(180deg,#070b18,#0b1020 60%,#070b18);
}
body::after{
  content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;
  background-image:linear-gradient(rgba(255,255,255,.028) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.028) 1px,transparent 1px);
  background-size:64px 64px;
  mask-image:radial-gradient(80% 60% at 50% 0%,#000 20%,transparent 85%);
  -webkit-mask-image:radial-gradient(80% 60% at 50% 0%,#000 20%,transparent 85%);
}
a{color:#bcd0ff;text-decoration:none}
a:hover{color:#fff}
.wrap{width:100%;max-width:1080px;margin:0 auto;padding:0 16px}
.muted{color:var(--muted)}
.small{font-size:13px}
.center{text-align:center}
.mt{margin-top:18px}
.mb{margin-bottom:18px}
.nowrap{white-space:nowrap}

/* ---------- 顶栏 ---------- */
.top{position:sticky;top:0;z-index:60;backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);
  background:rgba(7,11,24,.72);border-bottom:1px solid var(--line)}
.top-in{display:flex;align-items:center;gap:14px;min-height:62px}
.brandline{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:.2px;color:#fff}
.brandline .logo-mark{width:30px;height:30px;flex:0 0 auto}
.brandline small{display:block;font-weight:500;font-size:11px;color:var(--muted-2);letter-spacing:.16em}
.brandline .bt{display:flex;flex-direction:column;line-height:1.15}
.nav{margin-left:auto;display:flex;align-items:center;gap:4px;flex-wrap:wrap}
.nav a{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border-radius:11px;color:var(--muted);
  font-size:14px;font-weight:600;transition:.18s;border:1px solid transparent}
.nav a:hover{background:var(--panel);color:#fff}
.nav a.on{background:linear-gradient(135deg,rgba(99,102,241,.30),rgba(34,211,238,.18));color:#fff;border-color:rgba(255,255,255,.14)}
.nav a .ico{width:17px;height:17px;opacity:.9}
.nav-logout{margin-left:6px}
.nav-toggle{display:none;margin-left:auto;background:var(--panel);border:1px solid var(--line);color:#fff;
  width:42px;height:40px;border-radius:12px;font-size:18px;cursor:pointer}
@media(max-width:880px){
  .nav-toggle{display:block}
  .nav{position:absolute;left:12px;right:12px;top:calc(100% + 8px);flex-direction:column;align-items:stretch;
    background:rgba(10,15,32,.97);border:1px solid var(--line);border-radius:16px;padding:8px;
    box-shadow:var(--shadow);display:none}
  .nav.open{display:flex}
  .nav a{padding:11px 12px;font-size:15px}
  .nav-logout{margin:6px 0 0}
}

/* ---------- 通用块 ---------- */
main{padding:26px 0 60px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:20px;
  box-shadow:var(--shadow);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);margin-bottom:18px}
.panel h2{margin:0 0 14px;font-size:18px;letter-spacing:.2px;display:flex;align-items:center;gap:9px}
.panel h2 .ico{width:19px;height:19px;color:var(--violet)}
.panel h3{margin:18px 0 10px;font-size:15px;color:#fff}
.panel>p:first-child{margin-top:0}
.grid{display:grid;gap:14px}
.g2{grid-template-columns:repeat(2,minmax(0,1fr))}
.g3{grid-template-columns:repeat(3,minmax(0,1fr))}
.g4{grid-template-columns:repeat(4,minmax(0,1fr))}
@media(max-width:880px){.g3,.g4{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.g2,.g3,.g4{grid-template-columns:1fr}}
.stat{background:linear-gradient(160deg,rgba(255,255,255,.06),rgba(255,255,255,.02));border:1px solid var(--line);
  border-radius:var(--radius-sm);padding:14px 16px}
.stat-k{font-size:12px;color:var(--muted-2);letter-spacing:.08em;text-transform:uppercase}
.stat-v{font-size:22px;font-weight:800;margin-top:2px;font-variant-numeric:tabular-nums}
.stat-v small{font-size:12px;font-weight:600;color:var(--muted)}
.balance-hero{font-size:clamp(30px,7vw,44px);font-weight:800;letter-spacing:-.5px;
  background:linear-gradient(100deg,#fff,#c7d2fe 45%,var(--gold));-webkit-background-clip:text;background-clip:text;color:transparent}
.balance-hero small{font-size:15px;font-weight:700;-webkit-text-fill-color:var(--muted);color:var(--muted)}
.pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;
  background:rgba(99,102,241,.18);border:1px solid rgba(129,140,248,.35);color:#cdd6ff}
.pill-gold{background:rgba(245,196,81,.15);border-color:rgba(245,196,81,.4);color:#ffe6a8}
.pill-green{background:rgba(52,211,153,.14);border-color:rgba(52,211,153,.38);color:#a7f3d0}
.pill-red{background:rgba(251,113,133,.14);border-color:rgba(251,113,133,.4);color:#ffc9d3}
.pill-gray{background:rgba(255,255,255,.07);border-color:var(--line);color:var(--muted)}

/* ---------- 按钮 / 表单 ---------- */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 18px;border-radius:12px;
  font-size:15px;font-weight:700;cursor:pointer;border:1px solid transparent;transition:.18s;font-family:inherit;
  background:linear-gradient(135deg,var(--indigo),#7c3aed);color:#fff;box-shadow:0 10px 26px rgba(99,102,241,.34)}
.btn:hover{transform:translateY(-1px);box-shadow:0 14px 30px rgba(99,102,241,.44);color:#fff}
.btn:active{transform:translateY(0)}
.btn-ghost{background:var(--panel);border-color:var(--line-2);color:var(--text);box-shadow:none}
.btn-ghost:hover{background:var(--panel-2);box-shadow:none}
.btn-cyan{background:linear-gradient(135deg,#0ea5e9,var(--cyan));box-shadow:0 10px 26px rgba(34,211,238,.3)}
.btn-gold{background:linear-gradient(135deg,#f59e0b,var(--gold));color:#2a1a00;box-shadow:0 10px 26px rgba(245,196,81,.3)}
.btn-gold:hover{color:#2a1a00}
.btn-red{background:linear-gradient(135deg,#e11d48,#fb7185);box-shadow:0 10px 26px rgba(251,113,133,.3)}
.btn-sm{padding:7px 12px;font-size:13px;border-radius:10px}
.btn-block{width:100%}
.btn[disabled]{opacity:.5;cursor:not-allowed;transform:none}
.btn-row{display:flex;flex-wrap:wrap;gap:10px}
form{margin:0}
label{display:block;font-size:13px;font-weight:600;color:var(--muted);margin:14px 0 6px}
input,select,textarea{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--line-2);
  background:rgba(6,10,22,.72);color:var(--text);font-size:15px;font-family:inherit;transition:.16s}
input:focus,select:focus,textarea:focus{outline:none;border-color:rgba(129,140,248,.85);
  box-shadow:0 0 0 4px rgba(99,102,241,.18);background:rgba(6,10,22,.9)}
input::placeholder{color:var(--muted-2)}
select{appearance:none;-webkit-appearance:none;background-image:linear-gradient(45deg,transparent 50%,var(--muted) 50%),linear-gradient(135deg,var(--muted) 50%,transparent 50%);
  background-position:calc(100% - 18px) 50%,calc(100% - 13px) 50%;background-size:5px 5px,5px 5px;background-repeat:no-repeat;padding-right:38px}
.field-row{display:flex;gap:10px;flex-wrap:wrap}
.field-row>*{flex:1 1 160px;min-width:0}
.hint{font-size:12.5px;color:var(--muted-2);margin-top:8px}
.notice{background:rgba(99,102,241,.10);border:1px solid rgba(129,140,248,.30);border-radius:var(--radius-sm);
  padding:12px 14px;font-size:13.5px;color:#d5dcff}
.notice-warn{background:rgba(245,196,81,.10);border-color:rgba(245,196,81,.34);color:#ffe9b8}

/* ---------- 提示条 ---------- */
.flash{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:14px;margin:0 0 16px;font-size:14.5px;
  font-weight:600;border:1px solid var(--line);animation:flashIn .28s ease}
@keyframes flashIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.flash-success{background:rgba(52,211,153,.12);border-color:rgba(52,211,153,.4);color:#c6f7e4}
.flash-error{background:rgba(251,113,133,.12);border-color:rgba(251,113,133,.42);color:#ffd5dd}
.flash-info{background:rgba(99,102,241,.12);border-color:rgba(129,140,248,.36);color:#dbe2ff}
.flash-dot{width:8px;height:8px;border-radius:50%;background:currentColor;flex:0 0 auto;box-shadow:0 0 12px currentColor}
.flash-x{margin-left:auto;background:none;border:0;color:inherit;font-size:20px;line-height:1;cursor:pointer;opacity:.7}

/* ---------- 首屏 HERO ---------- */
.hero{display:grid;grid-template-columns:1.05fr .95fr;gap:26px;align-items:center;padding:18px 0 8px}
@media(max-width:900px){.hero{grid-template-columns:1fr;gap:18px}}
.hero h1{font-size:clamp(30px,5.6vw,50px);line-height:1.14;margin:14px 0 12px;letter-spacing:-1px}
.hero h1 span{background:linear-gradient(100deg,#8ab4ff,var(--violet) 45%,var(--cyan));
  -webkit-background-clip:text;background-clip:text;color:transparent}
.hero p.lead{font-size:16px;color:#c3ccdf;max-width:560px;margin:0 0 18px}
.hero .rate-line{font-size:15px;font-weight:700;color:#ffe6a8;margin:0 0 6px}
.slogan{font-size:13.5px;color:var(--muted);border-left:2px solid rgba(129,140,248,.6);padding-left:12px;margin:18px 0 0}
.cta{display:flex;gap:12px;flex-wrap:wrap;margin:22px 0 10px}
.trust{display:flex;gap:16px;flex-wrap:wrap;font-size:12.5px;color:var(--muted-2);margin-top:14px}
.trust b{color:var(--muted)}

/* ---------- 3D 卡片 ---------- */
.card-stage{display:flex;flex-direction:column;align-items:center;gap:14px;padding:6px 0}
.card-3d{width:min(92vw,420px);max-width:100%;perspective:1400px;touch-action:none;cursor:grab;outline:none;
  border-radius:24px;-webkit-tap-highlight-color:transparent}
.card-3d:active{cursor:grabbing}
.card-3d:focus-visible{box-shadow:0 0 0 3px rgba(129,140,248,.55)}
.card-inner{position:relative;width:100%;aspect-ratio:1.58/1;min-height:220px;transform-style:preserve-3d;
  will-change:transform}
.card-face{position:absolute;inset:0;border-radius:22px;padding:16px 19px;display:flex;flex-direction:column;
  justify-content:space-between;overflow:hidden;backface-visibility:hidden;-webkit-backface-visibility:hidden;
  box-shadow:0 26px 60px rgba(0,0,0,.6),inset 0 0 0 1px rgba(255,255,255,.09)}
.card-front{background:linear-gradient(135deg,#141b3c 0%,#1d1640 48%,#2c1c50 100%)}
.card-back{background:linear-gradient(135deg,#0e1730 0%,#19233f 52%,#0d2b36 100%);transform:rotateY(180deg)}
.holo{position:absolute;inset:-35%;pointer-events:none;mix-blend-mode:screen;filter:blur(8px);opacity:.55;
  background:conic-gradient(from 210deg,rgba(255,255,255,.16),transparent 22%,rgba(34,211,238,.22) 42%,transparent 58%,rgba(167,139,250,.22) 76%,transparent)}
.holo-back{opacity:.38;background:conic-gradient(from 30deg,rgba(245,196,81,.22),transparent 25%,rgba(34,211,238,.2) 50%,transparent 70%,rgba(167,139,250,.2))}
.card-lines{position:absolute;inset:0;pointer-events:none;opacity:.22;
  background-image:linear-gradient(rgba(255,255,255,.10) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.10) 1px,transparent 1px);
  background-size:34px 34px;mask-image:linear-gradient(160deg,#000,transparent 70%);
  -webkit-mask-image:linear-gradient(160deg,#000,transparent 70%)}
.cf-top,.cf-bottom,.cf-main{position:relative;z-index:2;display:flex}
.cf-top{align-items:flex-start;justify-content:space-between;gap:10px}
.card-brand{display:flex;align-items:center;gap:9px}
.card-brand .logo-mark{width:26px;height:26px}
.card-brand .cb-name{font-size:13.5px;font-weight:800;letter-spacing:.3px;color:#fff;line-height:1.15}
.card-brand .cb-sub{font-size:9.5px;letter-spacing:.22em;color:rgba(255,255,255,.55)}
.chip{width:38px;height:28px;border-radius:7px;background:linear-gradient(135deg,#f9d976,#d9a92a);
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.5);position:relative;flex:0 0 auto}
.chip::after{content:"";position:absolute;inset:6px 5px;border-radius:3px;
  background-image:linear-gradient(rgba(120,80,0,.5) 1px,transparent 1px),linear-gradient(90deg,rgba(120,80,0,.5) 1px,transparent 1px);
  background-size:9px 8px}
/* 卡片正面主体：编号 + 余额 */
.cf-main{flex-direction:column;gap:1px;margin:4px 0 2px}
.id-label{font-size:10px;letter-spacing:.24em;color:rgba(255,255,255,.55);text-transform:uppercase}
.id-value{font-family:ui-monospace,SFMono-Regular,Consolas,"Liberation Mono",Menlo,monospace;
  font-size:clamp(30px,9.6vw,44px);font-weight:800;letter-spacing:.1em;line-height:1.05;
  background:linear-gradient(96deg,#ffffff,#c9d4ff 42%,var(--gold));-webkit-background-clip:text;background-clip:text;color:transparent}
.bal-line{display:flex;align-items:baseline;gap:7px;margin-top:1px;flex-wrap:wrap}
.bal-k{font-size:10px;letter-spacing:.2em;color:rgba(255,255,255,.5);text-transform:uppercase}
.bal-v{font-size:clamp(19px,5.2vw,23px);font-weight:800;letter-spacing:-.3px;
  background:linear-gradient(100deg,#fff,#dcE1ff 55%,var(--gold));-webkit-background-clip:text;background-clip:text;color:transparent}
.bal-u{font-size:11.5px;font-weight:700;color:rgba(255,255,255,.62);letter-spacing:.1em}
.cf-bottom{gap:16px;flex-wrap:wrap}
.meta-k{font-size:9.5px;letter-spacing:.2em;color:rgba(255,255,255,.5);text-transform:uppercase}
.meta-v{font-size:13px;font-weight:700;color:#fff;word-break:break-all}
/* 卡片背面：大 logo 展示 */
.cb-center{position:relative;z-index:2;display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;
  flex:1;justify-content:center}
.cb-center .logo-mark{width:74px;height:74px;filter:drop-shadow(0 8px 20px rgba(34,211,238,.35))}
.cb-center .logo-big{width:96px;height:96px;filter:drop-shadow(0 10px 26px rgba(99,102,241,.55)) drop-shadow(0 0 22px rgba(34,211,238,.28))}
.cb-word{font-size:12.5px;letter-spacing:.4em;color:rgba(255,255,255,.78);text-transform:uppercase}
.cb-tag{font-size:10.5px;letter-spacing:.2em;color:rgba(255,255,255,.5);text-transform:uppercase}
.card-back::after{content:"";position:absolute;left:50%;top:46%;width:230px;height:230px;transform:translate(-50%,-50%);
  border-radius:50%;background:radial-gradient(circle,rgba(129,140,248,.30),rgba(34,211,238,.10) 45%,transparent 70%);
  pointer-events:none;z-index:1}
.cb-foot{position:relative;z-index:2;display:flex;justify-content:space-between;gap:10px;font-size:10.5px;
  letter-spacing:.12em;color:rgba(255,255,255,.55);text-transform:uppercase}
.card-tools{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:center}
.card-tools .hint{margin:0}
.flip-tip{font-size:12.5px;color:var(--muted-2)}

/* ---------- 账单表格 ---------- */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:var(--radius-sm);border:1px solid var(--line)}
table{width:100%;border-collapse:collapse;font-size:13.5px;min-width:560px}
thead th{text-align:left;padding:11px 12px;background:rgba(255,255,255,.05);color:var(--muted);
  font-size:12px;font-weight:700;letter-spacing:.04em;white-space:nowrap}
tbody td{padding:11px 12px;border-top:1px solid var(--line);vertical-align:top}
tbody tr:hover{background:rgba(255,255,255,.028)}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.pos{color:#6ee7b7;font-weight:700}
.neg{color:#fda4af;font-weight:700}
.type-chip{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11.5px;font-weight:700;
  background:rgba(255,255,255,.07);border:1px solid var(--line);color:var(--muted)}
.pager{display:flex;align-items:center;gap:8px;justify-content:center;margin-top:14px;flex-wrap:wrap}
.pager a,.pager span{padding:7px 12px;border-radius:10px;border:1px solid var(--line);background:var(--panel);
  font-size:13px;font-weight:600}
.pager .cur{background:linear-gradient(135deg,var(--indigo),#7c3aed);border-color:transparent;color:#fff}
.pager .off{opacity:.4}
@media(max-width:760px){
  .table-wrap{border:0}
  table.rwd{min-width:0}
  table.rwd thead{display:none}
  table.rwd,table.rwd tbody,table.rwd tr,table.rwd td{display:block;width:100%}
  table.rwd tr{border:1px solid var(--line);border-radius:14px;margin-bottom:10px;padding:8px 12px;
    background:rgba(255,255,255,.026)}
  table.rwd td{border:0;padding:5px 0;display:flex;justify-content:space-between;gap:14px;text-align:right}
  table.rwd td::before{content:attr(data-label);color:var(--muted-2);font-size:12px;font-weight:700;
    flex:0 0 auto;text-align:left}
  table.rwd td.full{display:block;text-align:left}
  table.rwd td.full::before{display:block;margin-bottom:2px}
}

/* ---------- 账户列表（后台） ---------- */
.account{border:1px solid var(--line);border-radius:var(--radius-sm);padding:14px;margin-bottom:12px;
  background:linear-gradient(160deg,rgba(255,255,255,.05),rgba(255,255,255,.015))}
.account-top{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start}
.account-name{font-weight:800;font-size:16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.account-bal{font-size:17px;font-weight:800;color:#ffe6a8;font-variant-numeric:tabular-nums}
.account form{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center}
.account form input,.account form select{width:auto;flex:1 1 120px;min-width:96px;padding:9px 11px;font-size:13.5px}
.account form button{flex:0 0 auto}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;
  background:rgba(99,102,241,.2);border:1px solid rgba(129,140,248,.4);color:#cdd6ff}
.sep{border:0;border-top:1px solid var(--line);margin:16px 0}

/* ---------- 首页特性 / 步骤 ---------- */
.feature{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-sm);padding:16px}
.feature .fi{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,rgba(99,102,241,.35),rgba(34,211,238,.22));border:1px solid var(--line-2);margin-bottom:10px}
.feature .fi .ico{width:20px;height:20px;color:#dbe4ff}
.feature h3{margin:0 0 6px;font-size:15px;color:#fff}
.feature p{margin:0;font-size:13.5px;color:var(--muted)}
.step{display:flex;gap:12px;align-items:flex-start}
.step-n{flex:0 0 auto;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-weight:800;font-size:14px;background:linear-gradient(135deg,var(--indigo),var(--cyan));color:#fff}
.step-b h3{margin:2px 0 4px;font-size:14.5px;color:#fff}
.step-b p{margin:0;font-size:13px;color:var(--muted)}
details{border:1px solid var(--line);border-radius:var(--radius-sm);padding:12px 14px;background:var(--panel);margin-bottom:10px}
details[open]{background:var(--panel-2)}
summary{cursor:pointer;font-weight:700;font-size:14.5px;list-style:none}
summary::-webkit-details-marker{display:none}
summary::before{content:"+";display:inline-block;width:18px;color:var(--cyan);font-weight:800}
details[open] summary::before{content:"–"}
details p{margin:10px 0 0;font-size:13.5px;color:var(--muted)}

/* ---------- 汇率换算 ---------- */
.conv{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end}
@media(max-width:700px){.conv{grid-template-columns:1fr}}
.conv-out{margin-top:16px;padding:18px;border-radius:var(--radius-sm);text-align:center;
  background:linear-gradient(160deg,rgba(99,102,241,.22),rgba(34,211,238,.12));border:1px solid rgba(129,140,248,.36)}
.conv-out .co-v{font-size:clamp(26px,7vw,38px);font-weight:800;letter-spacing:-.5px;color:#fff;word-break:break-all}
.conv-out .co-sub{font-size:13.5px;color:#cdd6ff;margin-top:6px}
.rate-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.rate-item{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:10px 12px;border-radius:11px;
  background:rgba(255,255,255,.04);border:1px solid var(--line);font-size:13.5px}
.rate-item b{font-variant-numeric:tabular-nums;color:#ffe6a8}
.rate-item span{color:var(--muted)}

/* ---------- 底部 ---------- */
footer{border-top:1px solid var(--line);padding:22px 0 34px;color:var(--muted-2);font-size:12.5px}
footer .frow{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center}
footer a{color:var(--muted)}
.toast-copy{position:fixed;left:50%;bottom:28px;transform:translateX(-50%);background:rgba(10,15,32,.96);
  border:1px solid var(--line-2);color:#fff;padding:10px 16px;border-radius:12px;font-size:13.5px;font-weight:600;
  box-shadow:var(--shadow);z-index:99;opacity:0;transition:.2s;pointer-events:none}
.toast-copy.show{opacity:1}

/* ---------- 补充组件 ---------- */
.narrow{max-width:460px;margin:0 auto}
.code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;background:rgba(255,255,255,.08);
  border:1px solid var(--line);border-radius:6px;padding:1px 6px;font-size:13px;color:#dbe4ff}
.bar{height:9px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid var(--line);overflow:hidden;margin-top:6px}
.bar>i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--indigo),var(--cyan))}
.fill-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.fill-chip{padding:6px 11px;border-radius:999px;font-size:12.5px;font-weight:600;cursor:pointer;
  background:rgba(255,255,255,.06);border:1px solid var(--line-2);color:var(--muted);font-family:inherit;transition:.16s}
.fill-chip:hover{background:rgba(129,140,248,.2);color:#fff;border-color:rgba(129,140,248,.55)}
.ico{width:18px;height:18px;flex:0 0 auto}
.btn .ico{width:17px;height:17px}
.pill .ico{width:14px;height:14px}
ul.muted li{margin-bottom:4px}
@media (prefers-reduced-motion: reduce){*{animation:none!important;transition:none!important}}
</style>
</head>
<body>
<header class="top">
  <div class="wrap top-in">
    <a class="brandline" href="index.php">
      <?= logoMark('navGrad') ?>
      <span class="bt">
        <span><?= h(APP_SHORT) ?></span>
        <small>MOUNTAIN&nbsp;元</small>
      </span>
    </a>
    <button type="button" class="nav-toggle" id="navToggle" aria-label="展开菜单" aria-expanded="false">&#9776;</button>
    <nav class="nav" id="mainNav">
      <?php foreach (navItems($user) as $item): ?>
        <?php
        $isOn = ($item['page'] === $page);
        if (isset($item['tab'])) {
            $isOn = $isOn && ((string)($_GET['tab'] ?? 'users') === $item['tab']);
        }
        $href = 'index.php?page=' . rawurlencode((string)$item['page']);
        if (isset($item['tab'])) {
            $href .= '&amp;tab=' . rawurlencode((string)$item['tab']);
        }
        ?>
        <a class="<?= $isOn ? 'on' : '' ?>" href="<?= $href ?>"><?= icon((string)$item['icon']) ?><span><?= h((string)$item['label']) ?></span></a>
      <?php endforeach; ?>
      <?php if ($user !== null): ?>
        <form method="post" class="nav-logout">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="logout">
          <button type="submit" class="btn btn-ghost btn-sm"><?= icon('logout') ?> 退出登录</button>
        </form>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main>
  <div class="wrap">
    <?php renderFlashBar($flash); ?>
    <?php
}

/**
 * 初始化安装页：系统还没有管理员时展示，创建完成后不再出现。
 */
function renderSetupPage(?array $flash, array $old, string $error): void
{
    renderHead('初始化安装', null, $flash, 'setup');

    $username = (string)($old['username'] ?? '');
    ?>
    <div class="narrow">
      <section class="panel">
        <h2><?= icon('shield') ?> 初始化安装</h2>
        <p class="muted small">
          检测到系统还没有管理员账户。请在这里创建你自己的管理员账号 ——
          这一步只会出现一次，创建完成后安装入口会自动关闭。
        </p>

        <?php if ($error !== ''): ?>
          <div class="flash flash-error"><span class="flash-dot"></span><span><?= h($error) ?></span></div>
        <?php endif; ?>

        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="setup">

          <label for="setup_username">管理员用户名</label>
          <input id="setup_username" name="username" required maxlength="24"
                 value="<?= h($username) ?>" autocomplete="username" autofocus>

          <label for="setup_password">管理员密码（至少 8 位）</label>
          <input id="setup_password" name="password" type="password" required minlength="8"
                 autocomplete="new-password">

          <label for="setup_password_again">再输一次密码</label>
          <input id="setup_password_again" name="password_again" type="password" required minlength="8"
                 autocomplete="new-password">

          <div class="mt">
            <button type="submit" class="btn btn-block"><?= icon('shield') ?> 创建管理员并进入后台</button>
          </div>
        </form>

        <div class="notice mt">
          安全建议：使用独立的高强度密码；部署完成后确认站点已开启 HTTPS；
          定期备份 <span class="code">data/</span> 目录。
        </div>
      </section>
    </div>
    <?php
    renderFoot();
}

function renderFoot(): void
{
    ?>
  </div>
</main>
<footer>
  <div class="wrap frow">
    <div>
      <?= h(APP_NAME) ?>
      <?php if (contactLine() !== ''): ?>&nbsp;|&nbsp; <?= h(contactLine()) ?><?php endif; ?>
    </div>
    <div>数据本地存储 · 不使用外部 CDN · <a href="index.php?page=exchange">汇率换算</a></div>
  </div>
  <div class="wrap" style="margin-top:12px;line-height:1.75;opacity:.85">
    本站积分为站内娱乐用途的演示数据，与现实货币无关，<b>不构成任何投资、兑换或收益承诺</b>；
    请勿将本程序用于以虚拟货币、积分兑换等名义向他人收取资金的活动。
  </div>
</footer>
<div class="toast-copy" id="copyToast">已复制</div>
<script>
(function () {
  /* ---------- 移动端菜单 ---------- */
  var toggle = document.getElementById('navToggle');
  var nav = document.getElementById('mainNav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* ---------- 提示条自动消失 ---------- */
  var flashBar = document.getElementById('flashBar');
  if (flashBar) {
    setTimeout(function () {
      flashBar.style.transition = 'opacity .4s, transform .4s';
      flashBar.style.opacity = '0';
      flashBar.style.transform = 'translateY(-6px)';
      setTimeout(function () { if (flashBar.parentNode) { flashBar.parentNode.removeChild(flashBar); } }, 420);
    }, 6000);
  }

  /* ---------- 3D 卡片：拖动旋转 / 翻转 / 复位 ---------- */
  var stage = document.getElementById('mtdCard');
  var inner = document.getElementById('cardInner');
  if (stage && inner) {
    var rx = -9, ry = 0, flipped = false, dragging = false, lastX = 0, lastY = 0, vx = 0, vy = 0, raf = null;

    function paint() {
      inner.style.transform = 'rotateX(' + rx.toFixed(2) + 'deg) rotateY(' + (ry + (flipped ? 180 : 0)).toFixed(2) + 'deg)';
    }
    function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }
    function loop() {
      if (!dragging) {
        vx *= 0.94; vy *= 0.94;
        if (Math.abs(vx) < 0.03 && Math.abs(vy) < 0.03) { vx = 0; vy = 0; raf = null; return; }
        ry += vx; rx = clamp(rx - vy, -38, 38);
        paint();
      }
      raf = requestAnimationFrame(loop);
    }
    function start(x, y) {
      dragging = true; lastX = x; lastY = y; vx = 0; vy = 0;
      if (raf) { cancelAnimationFrame(raf); raf = null; }
      stage.style.cursor = 'grabbing';
    }
    function move(x, y) {
      if (!dragging) { return; }
      var dx = x - lastX, dy = y - lastY;
      lastX = x; lastY = y;
      ry += dx * 0.55;
      rx = clamp(rx - dy * 0.38, -38, 38);
      vx = dx * 0.55 * 0.6; vy = dy * 0.38 * 0.6;
      paint();
    }
    function end() {
      if (!dragging) { return; }
      dragging = false;
      stage.style.cursor = 'grab';
      if (!raf) { raf = requestAnimationFrame(loop); }
    }

    stage.addEventListener('pointerdown', function (e) {
      if (e.button !== undefined && e.button !== 0) { return; }
      if (stage.setPointerCapture) { try { stage.setPointerCapture(e.pointerId); } catch (err) {} }
      start(e.clientX, e.clientY);
      e.preventDefault();
    }, { passive: false });
    stage.addEventListener('pointermove', function (e) { move(e.clientX, e.clientY); });
    stage.addEventListener('pointerup', end);
    stage.addEventListener('pointercancel', end);
    stage.addEventListener('pointerleave', end);

    /* 触屏兜底（老浏览器不支持 pointer events 时） */
    if (!window.PointerEvent) {
      stage.addEventListener('touchstart', function (e) {
        var t = e.touches[0]; start(t.clientX, t.clientY);
      }, { passive: true });
      stage.addEventListener('touchmove', function (e) {
        var t = e.touches[0]; move(t.clientX, t.clientY); e.preventDefault();
      }, { passive: false });
      stage.addEventListener('touchend', end);
    }

    stage.addEventListener('dblclick', function () { flipped = !flipped; paint(); });
    stage.addEventListener('keydown', function (e) {
      var k = e.key;
      if (k === 'ArrowLeft') { ry -= 6; }
      else if (k === 'ArrowRight') { ry += 6; }
      else if (k === 'ArrowUp') { rx = clamp(rx + 5, -38, 38); }
      else if (k === 'ArrowDown') { rx = clamp(rx - 5, -38, 38); }
      else if (k === 'Enter' || k === ' ') { flipped = !flipped; }
      else { return; }
      e.preventDefault();
      paint();
    });

    var flipBtn = document.getElementById('btnFlip');
    if (flipBtn) {
      flipBtn.addEventListener('click', function () { flipped = !flipped; paint(); });
    }
    var resetBtn = document.getElementById('btnReset');
    if (resetBtn) {
      resetBtn.addEventListener('click', function () { rx = -9; ry = 0; flipped = false; paint(); });
    }
    paint();
  }

  /* ---------- 点击编号复制 ---------- */
  var toast = document.getElementById('copyToast');
  function showToast(text) {
    if (!toast) { return; }
    toast.textContent = text;
    toast.classList.add('show');
    setTimeout(function () { toast.classList.remove('show'); }, 1400);
  }
  document.querySelectorAll('[data-copy]').forEach(function (el) {
    el.style.cursor = 'pointer';
    el.addEventListener('click', function () {
      var text = el.getAttribute('data-copy') || '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { showToast('已复制：' + text); }, function () {});
      } else {
        var tmp = document.createElement('textarea');
        tmp.value = text; document.body.appendChild(tmp); tmp.select();
        try { document.execCommand('copy'); showToast('已复制：' + text); } catch (err) {}
        document.body.removeChild(tmp);
      }
    });
  });

  /* ---------- 转账金额手续费提示 ---------- */
  var amtInput = document.getElementById('amountInput');
  var feeTip = document.getElementById('feeTip');
  if (amtInput && feeTip) {
    var rate = parseFloat(feeTip.getAttribute('data-rate') || '0.05');
    var min = parseFloat(feeTip.getAttribute('data-min') || '50');
    var update = function () {
      var v = parseFloat((amtInput.value || '').replace(',', '.'));
      if (isNaN(v) || v <= 0) { feeTip.textContent = '单笔超过 ' + min + ' MTD 收取 ' + (rate * 100) + '% 手续费。'; return; }
      if (v > min) {
        var fee = Math.ceil(v * rate * 1000) / 1000;
        feeTip.textContent = '手续费 ' + fee.toFixed(3) + ' MTD，实际扣除 ' + (v + fee).toFixed(3) + ' MTD。';
      } else {
        feeTip.textContent = '未超过 ' + min + ' MTD，本次免手续费。';
      }
    };
    amtInput.addEventListener('input', update);
    update();
  }
})();
</script>
</body>
</html>
    <?php
}


/* ============================================================================
 * 8. 页面
 * ==========================================================================*/

$page = (string)($_GET['page'] ?? 'home');

if (!in_array($page, ['home', 'login', 'register', 'transfer', 'checkin', 'quiz', 'exchange', 'bills', 'security', 'admin'], true)) {
    $page = 'home';
}

$loggedUser  = currentUser($data);
$flashMsg    = getFlash();
$rates       = resolveRates($data['settings']);
$mtdPerCny   = resolveMtdPerCny($data['settings']);
$currencyNames = currencyLabels();

/* 已登录就不该再看登录 / 注册页 */
if ($loggedUser !== null && ($page === 'login' || $page === 'register')) {
    redirect(q(['page' => 'home']));
}

/** 编号 / 用户名 的展示形式 */
function handleLabel(array $data, ?string $id): string
{
    if ($id === null || $id === '') {
        return '系统';
    }

    $index = userIndexById($data['users'], (string)$id);

    if ($index < 0) {
        return pad4((string)$id);
    }

    return (string)$data['users'][$index]['username'] . '（' . pad4((string)$data['users'][$index]['id']) . '）';
}

/** 账单表格（$viewerId 用于判断收入/支出，空字符串表示后台视角） */
function renderTxnTable(array $rows, array $data, string $viewerId): void
{
    ?>
    <div class="table-wrap">
      <table class="rwd">
        <thead>
        <tr>
          <th>时间</th>
          <th>类型</th>
          <th>来源</th>
          <th>去向</th>
          <th class="num">金额</th>
          <th class="num">手续费</th>
          <th>说明</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $txn): ?>
          <?php
          $isIncome   = $viewerId !== '' && (string)($txn['to_id'] ?? '') !== '' && idKey((string)$txn['to_id']) === idKey($viewerId);
          $isOutcome  = $viewerId !== '' && (string)($txn['from_id'] ?? '') !== '' && idKey((string)$txn['from_id']) === idKey($viewerId);
          $amountText = money((int)($txn['amount'] ?? 0));

          if ($isIncome) {
              $amountText = '+' . $amountText;
              $class = 'pos';
          } elseif ($isOutcome) {
              $amountText = '-' . $amountText;
              $class = 'neg';
          } else {
              $class = '';
          }
          ?>
          <tr>
            <td data-label="时间" class="nowrap"><?= h((string)($txn['created_at'] ?? '')) ?></td>
            <td data-label="类型"><span class="type-chip"><?= h(txnLabel((string)($txn['type'] ?? ''))) ?></span></td>
            <td data-label="来源"><?= h(handleLabel($data, $txn['from_id'] ?? null)) ?></td>
            <td data-label="去向"><?= h(handleLabel($data, $txn['to_id'] ?? null)) ?></td>
            <td data-label="金额" class="num <?= $class ?>"><?= h($amountText) ?> MTD</td>
            <td data-label="手续费" class="num"><?= h(money((int)($txn['fee'] ?? 0))) ?> MTD</td>
            <td data-label="说明" class="full"><?= h((string)($txn['description'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($rows)): ?>
          <tr>
            <td class="full center muted" colspan="7">暂无账单记录。</td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}

/** 分页条 */
function renderPager(array $slice, array $baseParams): void
{
    if ((int)$slice['pages'] <= 1) {
        return;
    }

    $page  = (int)$slice['page'];
    $pages = (int)$slice['pages'];
    ?>
    <div class="pager">
      <?php if ($page > 1): ?>
        <a href="<?= h(q(array_merge($baseParams, ['p' => $page - 1]))) ?>">上一页</a>
      <?php else: ?>
        <span class="off">上一页</span>
      <?php endif; ?>

      <span class="cur">第 <?= $page ?> / <?= $pages ?> 页</span>
      <span class="muted small">共 <?= (int)$slice['total'] ?> 条</span>

      <?php if ($page < $pages): ?>
        <a href="<?= h(q(array_merge($baseParams, ['p' => $page + 1]))) ?>">下一页</a>
      <?php else: ?>
        <span class="off">下一页</span>
      <?php endif; ?>
    </div>
    <?php
}

/** 3D 账户卡（$account 为 null 时展示示例卡） */
function renderAccountCard(?array $account, array $data, float $mtdPerCny): void
{
    $isDemo  = $account === null;
    $number  = $isDemo ? '0000' : pad4((string)$account['id']);
    $name    = $isDemo ? '新用户' : (string)$account['username'];
    $balance = $isDemo ? (int)REGISTER_BONUS : (int)$account['balance'];
    $roleTxt = $isDemo ? '示例卡' : ((($account['role'] ?? '') === 'admin') ? '管理员' : '普通会员');
    $opened  = $isDemo ? date('Y-m-d') : substr((string)$account['created_at'], 0, 10);
    ?>
    <div class="card-stage">
      <div class="card-3d" id="mtdCard" tabindex="0" role="img"
           aria-label="账户卡，编号 <?= h($number) ?>，余额 <?= h(money($balance)) ?> MTD，可拖动旋转，按回车翻转">
        <div class="card-inner" id="cardInner">
          <div class="card-face card-front">
            <div class="holo"></div>
            <div class="card-lines"></div>
            <div class="cf-top">
              <div class="card-brand">
                <?= logoMark('cardGradA') ?>
                <span>
                  <span class="cb-name">Mountain元</span>
                  <span class="cb-sub">MTD MEMBER CARD</span>
                </span>
              </div>
              <div class="chip"></div>
            </div>
            <div class="cf-main">
              <div class="id-label">账户编号</div>
              <div class="id-value" data-copy="<?= h($number) ?>" title="点击复制编号"><?= h($number) ?></div>
              <div class="bal-line">
                <span class="bal-k">余额</span>
                <span class="bal-v"><?= h(money($balance)) ?></span>
                <span class="bal-u">MTD</span>
              </div>
            </div>
            <div class="cf-bottom">
              <div>
                <div class="meta-k">持卡人</div>
                <div class="meta-v"><?= h($name) ?></div>
              </div>
              <div>
                <div class="meta-k">身份</div>
                <div class="meta-v"><?= h($roleTxt) ?></div>
              </div>
              <div>
                <div class="meta-k">开户</div>
                <div class="meta-v"><?= h($opened) ?></div>
              </div>
            </div>
          </div>

          <div class="card-face card-back">
            <div class="holo holo-back"></div>
            <div class="card-lines"></div>
            <div class="cb-center">
              <?= logoMark('cardGradB', 'logo-mark logo-big') ?>
              <div class="cb-word">Mountain&nbsp;元</div>
              <div class="cb-tag">山高我为峰 · MTD</div>
            </div>
            <div class="cb-foot">
              <span>NO. <?= h($number) ?></span>
              <span>1 CNY ≈ <?= h(formatNumber($mtdPerCny)) ?> MTD</span>
            </div>
          </div>
        </div>
      </div>

      <div class="card-tools">
        <button type="button" class="btn btn-ghost btn-sm" id="btnFlip">翻转卡片</button>
        <button type="button" class="btn btn-ghost btn-sm" id="btnReset">复位角度</button>
        <span class="hint">按住卡片拖动可自由旋转，双击也能翻面</span>
      </div>
    </div>
    <?php
}

/** 汇率换算器（服务端计算，禁用 JS 也能用） */
function renderConverter(array $rates, float $mtdPerCny, string $page, array $extraParams = []): void
{
    $names    = currencyLabels();
    $units    = currencyUnits();
    $codes    = array_merge(['CNY'], array_keys($rates));
    $codes    = array_values(array_unique($codes));
    $codes[]  = 'MTD';

    $from   = strtoupper(trim((string)($_GET['from'] ?? 'CNY')));
    $to     = strtoupper(trim((string)($_GET['to'] ?? 'MTD')));
    $amountRaw = trim((string)($_GET['amount'] ?? '1'));

    if (!in_array($from, $codes, true)) {
        $from = 'CNY';
    }

    if (!in_array($to, $codes, true)) {
        $to = 'MTD';
    }

    $amount = is_numeric(str_replace(',', '.', $amountRaw)) ? (float)str_replace(',', '.', $amountRaw) : 0.0;
    $hasResult = isset($_GET['calc']) && $amount > 0;

    $resultValue = 0.0;
    $cnyValue    = 0.0;

    if ($hasResult) {
        $resultValue = convertCurrency($amount, $from, $to, $rates, $mtdPerCny);
        $cnyValue    = toCny($amount, $from, $rates, $mtdPerCny);
    }

    $fromLabel = ($names[$from] ?? $from);
    $toLabel   = ($names[$to] ?? $to);
    ?>
    <form method="get" action="index.php">
      <input type="hidden" name="page" value="<?= h($page) ?>">
      <?php foreach ($extraParams as $k => $v): ?>
        <input type="hidden" name="<?= h((string)$k) ?>" value="<?= h((string)$v) ?>">
      <?php endforeach; ?>
      <input type="hidden" name="calc" value="1">

      <div class="conv">
        <div>
          <label for="conv-amount">金额</label>
          <input id="conv-amount" name="amount" value="<?= h($amountRaw !== '' ? $amountRaw : '1') ?>"
                 inputmode="decimal" autocomplete="off" placeholder="例如 100">
        </div>
        <div>
          <label for="conv-from">从</label>
          <select id="conv-from" name="from">
            <?php foreach ($codes as $code): ?>
              <option value="<?= h($code) ?>" <?= $code === $from ? 'selected' : '' ?>>
                <?= h(($names[$code] ?? $code) . ' (' . $code . ')') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="conv-to">换算成</label>
          <select id="conv-to" name="to">
            <?php foreach ($codes as $code): ?>
              <option value="<?= h($code) ?>" <?= $code === $to ? 'selected' : '' ?>>
                <?= h(($names[$code] ?? $code) . ' (' . $code . ')') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="btn-row mt">
        <button type="submit" class="btn"><?= icon('rate') ?> 换算</button>
        <a class="btn btn-ghost" href="<?= h(q(['page' => $page] + $extraParams)) ?>">重置</a>
      </div>
    </form>

    <?php if ($hasResult): ?>
      <div class="conv-out">
        <div class="co-sub"><?= h(formatNumber($amount) . ' ' . $from . '（' . $fromLabel . '）') ?> 约等于</div>
        <div class="co-v"><?= h(formatNumber($resultValue)) ?> <?= h($to) ?></div>
        <div class="co-sub">
          <?= h($toLabel) ?> · 折合人民币 <?= h(formatNumber($cnyValue)) ?> 元
          &nbsp;|&nbsp; 1 <?= h($from) ?> ≈ <?= h(formatNumber(convertCurrency(1.0, $from, $to, $rates, $mtdPerCny))) ?> <?= h($to) ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="hint">
      参考汇率：1 元人民币 = <?= h(formatNumber($mtdPerCny)) ?> MTD；
      <?= h($units['CNY'] ?? '') ?>计算口径为 1 单位外币折合多少人民币。汇率为站内参考值，实际兑换以管理员确认为准。
    </div>
    <?php
}

/* ============================================================================
 * 8.1 首页
 * ==========================================================================*/

if ($page === 'home') {
    $txnCount = count($data['transactions']);

    renderHead($loggedUser === null ? '首页' : '我的账户卡', $loggedUser, $flashMsg, 'home');

    if ($loggedUser === null) {
        ?>
        <section class="hero">
          <div>
            <span class="pill pill-gold"><?= icon('gift') ?> 注册即送 5.000 MTD</span>
            <h1>把每一次往来，<br>记成看得见的<span style="white-space:nowrap">&nbsp;Mountain元</span></h1>
            <p class="rate-line">1 元人民币 ≈ <?= h(formatNumber($mtdPerCny)) ?> MTD ｜ 初始发行量 10,000 MTD</p>
            <p class="lead">
              一套轻量、透明、好玩的站内积分系统：注册即得启动金，每日签到与答题都能获得奖励，
              转账支持「4 位编号」或「用户名」，还能随时用内置汇率换算查看等值金额。
            </p>
            <div class="cta">
              <a class="btn" href="<?= h(q(['page' => 'register'])) ?>"><?= icon('gift') ?> 立即注册领取</a>
              <a class="btn btn-ghost" href="<?= h(q(['page' => 'login'])) ?>">我已有账户</a>
              <a class="btn btn-ghost" href="<?= h(q(['page' => 'exchange'])) ?>"><?= icon('rate') ?> 汇率换算</a>
            </div>
            <div class="trust">
              <span><b>无需数据库</b> 单文件即可部署</span>
              <span><b>数据自持</b> 存在你自己的主机</span>
              <span><b>手机优化</b> 移动端同样顺手</span>
            </div>
            <p class="slogan">山高我为峰 —— 奖励顺手可得，账目清清楚楚。</p>
            <p class="hint">本站积分为站内娱乐用途的演示数据，与现实货币无关，不构成任何投资或兑换承诺。</p>
          </div>
          <div>
            <?php renderAccountCard(null, $data, $mtdPerCny); ?>
          </div>
        </section>

        <section class="grid g4 mb">
          <div class="stat"><div class="stat-k">有效账户</div><div class="stat-v"><?= countActiveUsers($data) ?> <small>个</small></div></div>
          <div class="stat"><div class="stat-k">资金池余额</div><div class="stat-v"><?= h(money(poolOf($data))) ?> <small>MTD</small></div></div>
          <div class="stat"><div class="stat-k">累计账单</div><div class="stat-v"><?= $txnCount ?> <small>笔</small></div></div>
          <div class="stat"><div class="stat-k">今日签到</div><div class="stat-v"><?= countTodayCheckins($data) ?> <small>人</small></div></div>
        </section>

        <section class="panel">
          <h2><?= icon('card') ?> 六大能力</h2>
          <div class="grid g3">
            <div class="feature"><div class="fi"><?= icon('checkin') ?></div><h3>每日签到</h3><p>每天一次，稳定领取 0.100 MTD，坚持就有积累。</p></div>
            <div class="feature"><div class="fi"><?= icon('quiz') ?></div><h3>答题领奖</h3><p>10 以内乘法题，答对即得 0.100 MTD，做题也有收益。</p></div>
            <div class="feature"><div class="fi"><?= icon('transfer') ?></div><h3>双通道转账</h3><p>输入 4 位编号或用户名都能转账，附带备注一并发给对方。</p></div>
            <div class="feature"><div class="fi"><?= icon('lock') ?></div><h3>6 位转账密码</h3><p>可独立设置 6 位数字密码，连续输错自动锁定，余额更安心。</p></div>
            <div class="feature"><div class="fi"><?= icon('rate') ?></div><h3>实时汇率换算</h3><p>内置 8 种货币与 MTD 的换算，服务端计算，无网也能用。</p></div>
            <div class="feature"><div class="fi"><?= icon('card') ?></div><h3>3D 账户卡</h3><p>你的专属卡片，正面是编号，背面是余额，可以用手拖着转。</p></div>
          </div>
        </section>

        <section class="panel">
          <h2><?= icon('rate') ?> 快速换算</h2>
          <?php renderConverter($rates, $mtdPerCny, 'home'); ?>
        </section>

        <section class="panel">
          <h2><?= icon('shield') ?> 三步上手</h2>
          <div class="grid g3">
            <div class="step"><div class="step-n">1</div><div class="step-b"><h3>注册账户</h3><p>填写用户名与密码，自动分配到 4 位专属编号，立得 5.000 MTD。</p></div></div>
            <div class="step"><div class="step-n">2</div><div class="step-b"><h3>每天收奖励</h3><p>签到 + 答题，每天都有稳定进账，还能查看自己的完整账单。</p></div></div>
            <div class="step"><div class="step-n">3</div><div class="step-b"><h3>转账与换算</h3><p>用编号或用户名转给朋友，需要时用汇率换算看看等值多少。</p></div></div>
          </div>
        </section>

        <section class="panel">
          <h2><?= icon('shield') ?> 常见问题</h2>
          <details open><summary>MTD 是什么？</summary><p>MTD（Mountain元）是本站在站内使用的积分单位，1 元人民币约等于 <?= h(formatNumber($mtdPerCny)) ?> MTD，全部账目记录在本站自己的数据文件中，随时可查。</p></details>
          <details><summary>怎么获得 MTD？</summary><p>三条途径：注册赠送 5.000 MTD；每日签到 0.100 MTD；答题奖励 0.100 MTD／题。奖励统一从资金池发放。</p></details>
          <details><summary>转账要手续费吗？</summary><p>单笔超过 <?= h(money(TRANSFER_FEE_MIN)) ?> MTD 收取 <?= h((string)(int)round(TRANSFER_FEE_RATE * 100)) ?>% 手续费（向上取整到 0.001 MTD），手续费进入资金池；小额转账免手续费。</p></details>
          <details><summary>忘记转账密码怎么办？</summary>
            <p>
              登录后到「安全设置」用登录密码重新设置。
              <?php if (contactLine() !== ''): ?><?= h(contactLine('如果连登录密码也遗忘，请联系')) ?>协助处理。<?php endif; ?>
              站点管理员也可以在「管理后台 → 用户与余额」里重置用户的转账密码。
            </p>
          </details>
          <details><summary>数据安全吗？</summary><p>密码使用强哈希存储，转账密码同样加密保存；数据文件不会通过网址直接暴露，账单与余额只有你自己和管理员能看到。</p></details>
        </section>
        <?php
    } else {
        $myTransactions = array_values(array_filter(
            $data['transactions'],
            function (array $txn) use ($loggedUser): bool {
                return idKey((string)($txn['from_id'] ?? '')) === idKey((string)$loggedUser['id'])
                    || idKey((string)($txn['to_id'] ?? '')) === idKey((string)$loggedUser['id']);
            }
        ));

        $myRecent   = array_slice(array_reverse($myTransactions), 0, 8);
        $poolRatio  = INITIAL_POOL > 0 ? min(1.0, max(0.0, poolOf($data) / INITIAL_POOL)) : 0.0;

        $incomeSum = 0;
        $outcomeSum = 0;

        foreach ($myTransactions as $txn) {
            if (idKey((string)($txn['to_id'] ?? '')) === idKey((string)$loggedUser['id'])) {
                $incomeSum += (int)($txn['amount'] ?? 0);
            }

            if (idKey((string)($txn['from_id'] ?? '')) === idKey((string)$loggedUser['id'])) {
                $outcomeSum += (int)($txn['amount'] ?? 0) + (int)($txn['fee'] ?? 0);
            }
        }

        $isAdmin    = ($loggedUser['role'] ?? '') === 'admin';
        $checkedIn  = ((string)($loggedUser['last_checkin'] ?? '') === date('Y-m-d'));
        ?>
        <section class="panel">
          <div class="grid g2" style="align-items:center;gap:24px">
            <div>
              <?php renderAccountCard($loggedUser, $data, $mtdPerCny); ?>
            </div>
            <div>
              <span class="pill <?= $isAdmin ? 'pill-gold' : 'pill-green' ?>">
                <?= $isAdmin ? '管理员账户' : '普通会员' ?>
              </span>

              <div class="stat-k mt">当前余额</div>
              <div class="balance-hero"><?= h(money((int)$loggedUser['balance'])) ?><small> MTD</small></div>

              <div class="grid g2 mt">
                <div class="stat"><div class="stat-k">累计收入</div><div class="stat-v pos">+<?= h(money($incomeSum)) ?></div></div>
                <div class="stat"><div class="stat-k">累计支出</div><div class="stat-v neg">-<?= h(money($outcomeSum)) ?></div></div>
              </div>

              <div class="mt">
                <div class="stat-k">我的编号（点一下复制）</div>
                <div class="mt" style="font-family:ui-monospace,Consolas,monospace;font-size:22px;font-weight:800;letter-spacing:.16em">
                  <span data-copy="<?= h(pad4((string)$loggedUser['id'])) ?>"><?= h(pad4((string)$loggedUser['id'])) ?></span>
                </div>
              </div>

              <div class="mt">
                <div class="stat-k">资金池余量</div>
                <div class="bar"><i style="width:<?= h((string)round($poolRatio * 100, 2)) ?>%"></i></div>
                <div class="hint"><?= h(money(poolOf($data))) ?> MTD / <?= h(money(INITIAL_POOL)) ?> MTD</div>
              </div>

              <div class="btn-row mt">
                <?php if (!$isAdmin): ?>
                  <a class="btn <?= $checkedIn ? 'btn-ghost' : '' ?>" href="<?= h(q(['page' => 'checkin'])) ?>">
                    <?= icon('checkin') ?> <?= $checkedIn ? '今日已签到' : '去签到' ?>
                  </a>
                  <a class="btn btn-ghost" href="<?= h(q(['page' => 'quiz'])) ?>"><?= icon('quiz') ?> 去答题</a>
                  <a class="btn btn-cyan" href="<?= h(q(['page' => 'transfer'])) ?>"><?= icon('transfer') ?> 转账</a>
                <?php else: ?>
                  <a class="btn" href="<?= h(q(['page' => 'admin'])) ?>"><?= icon('admin') ?> 进入管理后台</a>
                <?php endif; ?>
                <a class="btn btn-ghost" href="<?= h(q(['page' => 'exchange'])) ?>"><?= icon('rate') ?> 汇率换算</a>
              </div>

              <?php if (!$isAdmin && !userHasPin($loggedUser)): ?>
                <div class="notice notice-warn mt">
                  还没有设置 6 位转账密码。设置后，转账需要额外验证密码，账户更安全。
                  <a href="<?= h(q(['page' => 'security'])) ?>">去设置 →</a>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <section class="grid g3">
          <div class="stat"><div class="stat-k">账户总数</div><div class="stat-v"><?= countActiveUsers($data) ?> <small>个</small></div></div>
          <div class="stat"><div class="stat-k">今日签到</div><div class="stat-v"><?= countTodayCheckins($data) ?> <small>人</small></div></div>
          <div class="stat"><div class="stat-k">我的账单</div><div class="stat-v"><?= count($myTransactions) ?> <small>笔</small></div></div>
        </section>

        <section class="panel">
          <h2><?= icon('bill') ?> 最近账单</h2>
          <?php renderTxnTable($myRecent, $data, (string)$loggedUser['id']); ?>
          <div class="btn-row mt">
            <a class="btn btn-ghost btn-sm" href="<?= h(q(['page' => 'bills'])) ?>">查看全部账单</a>
          </div>
        </section>
        <?php
    }

    renderFoot();
    exit;
}

/* ============================================================================
 * 8.2 登录 / 注册
 * ==========================================================================*/

if ($page === 'login') {
    renderHead('登录', null, $flashMsg, 'login');
    ?>
    <div class="narrow">
      <section class="panel">
        <h2><?= icon('user') ?> 登录账户</h2>
        <p class="muted small">用注册时的用户名和密码登录，登录后即可查看你的专属账户卡。</p>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="login">
          <label for="username">用户名</label>
          <input id="username" name="username" required maxlength="24" autocomplete="username" autofocus>
          <label for="password">密码</label>
          <input id="password" name="password" type="password" required autocomplete="current-password">
          <div class="mt">
            <button type="submit" class="btn btn-block"><?= icon('user') ?> 登录</button>
          </div>
        </form>
        <p class="hint center">还没有账户？<a href="<?= h(q(['page' => 'register'])) ?>">立即注册，送 5.000 MTD</a></p>
      </section>
    </div>
    <?php
    renderFoot();
    exit;
}

if ($page === 'register') {
    renderHead('注册', null, $flashMsg, 'register');
    ?>
    <div class="narrow">
      <section class="panel">
        <h2><?= icon('gift') ?> 注册新账户</h2>
        <p class="muted small">
          注册成功后会被随机分配一个 4 位专属编号，并立即获得 <?= h(money(REGISTER_BONUS)) ?> MTD 启动金。
        </p>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="register">
          <label for="username">用户名（2-24 位，支持中文、字母、数字、_ 和 -）</label>
          <input id="username" name="username" required maxlength="24" autocomplete="username" autofocus>
          <label for="password">登录密码（至少 6 位）</label>
          <input id="password" name="password" type="password" required minlength="6" autocomplete="new-password">
          <label for="password_again">确认密码</label>
          <input id="password_again" name="password_again" type="password" required minlength="6" autocomplete="new-password">
          <div class="mt">
            <button type="submit" class="btn btn-block"><?= icon('gift') ?> 注册并领取 5.000 MTD</button>
          </div>
        </form>
        <p class="hint center">已有账户？<a href="<?= h(q(['page' => 'login'])) ?>">直接登录</a></p>
      </section>
    </div>
    <?php
    renderFoot();
    exit;
}

/* ============================================================================
 * 8.3 汇率换算（游客也能用）
 * ==========================================================================*/

if ($page === 'exchange') {
    renderHead('汇率换算', $loggedUser, $flashMsg, 'exchange');
    ?>
    <section class="panel">
      <h2><?= icon('rate') ?> 汇率换算</h2>
      <p class="muted small">
        服务端实时计算，支持 <?= h(implode(' / ', array_keys(currencyLabels()))) ?>。
        汇率为站内参考值，由管理员在后台维护。
      </p>
      <?php renderConverter($rates, $mtdPerCny, 'exchange'); ?>
    </section>

    <section class="panel">
      <h2><?= icon('bill') ?> 当前参考汇率</h2>
      <div class="rate-list">
        <?php foreach ($currencyNames as $code => $label): ?>
          <div class="rate-item">
            <span><?= h($label) ?>（<?= h($code) ?>）</span>
            <b>
              <?php if ($code === 'MTD'): ?>
                1 CNY = <?= h(formatNumber($mtdPerCny)) ?>
              <?php else: ?>
                1 <?= h($code) ?> = <?= h(formatNumber((float)$rates[$code])) ?> 元
              <?php endif; ?>
            </b>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="hint">
        换算规则：先把金额折算为人民币，再折算成目标货币。
        MTD 与人民币的锚定关系为 1 元人民币 = <?= h(formatNumber($mtdPerCny)) ?> MTD。
      </div>
    </section>

    <?php if ($loggedUser === null): ?>
      <section class="panel center">
        <h2 style="justify-content:center"><?= icon('gift') ?> 想把这些数字变成自己的余额？</h2>
        <p class="muted">注册即得 <?= h(money(REGISTER_BONUS)) ?> MTD，每天签到答题继续积累。</p>
        <div class="cta" style="justify-content:center">
          <a class="btn" href="<?= h(q(['page' => 'register'])) ?>">免费注册</a>
          <a class="btn btn-ghost" href="<?= h(q(['page' => 'login'])) ?>">登录</a>
        </div>
      </section>
    <?php endif; ?>
    <?php
    renderFoot();
    exit;
}

/* ============================================================================
 * 8.4 需要登录的页面
 * ==========================================================================*/

if ($page === 'transfer') {
    $me = requireLogin($data);

    if (($me['role'] ?? '') === 'admin') {
        flash('info', '管理员账户不参与转账，请使用管理后台调整余额。');
        redirect(q(['page' => 'admin']));
    }

    /* 最近往来过的对象，方便一键填入 */
    $recentHandles = [];

    foreach (array_reverse($data['transactions']) as $txn) {
        $otherId = null;

        if (idKey((string)($txn['from_id'] ?? '')) === idKey((string)$me['id'])) {
            $otherId = $txn['to_id'] ?? null;
        } elseif (idKey((string)($txn['to_id'] ?? '')) === idKey((string)$me['id'])) {
            $otherId = $txn['from_id'] ?? null;
        }

        if ($otherId === null || $otherId === '') {
            continue;
        }

        $index = userIndexById($data['users'], (string)$otherId);

        if ($index < 0 || ($data['users'][$index]['role'] ?? '') === 'admin') {
            continue;
        }

        $handle = (string)$data['users'][$index]['username'];

        if (!isset($recentHandles[$handle])) {
            $recentHandles[$handle] = pad4((string)$data['users'][$index]['id']);
        }

        if (count($recentHandles) >= 6) {
            break;
        }
    }

    renderHead('转账', $me, $flashMsg, 'transfer');
    ?>
    <section class="panel">
      <h2><?= icon('transfer') ?> 转账</h2>
      <p class="muted small">
        收款人可以填 4 位编号，也可以直接填用户名（如 <span class="code">mountain</span>）。
        单笔超过 <?= h(money(TRANSFER_FEE_MIN)) ?> MTD 收取 <?= h((string)(int)round(TRANSFER_FEE_RATE * 100)) ?>% 手续费。
      </p>

      <div class="grid g2">
        <div class="stat">
          <div class="stat-k">我的余额</div>
          <div class="stat-v"><?= h(money((int)$me['balance'])) ?> <small>MTD</small></div>
        </div>
        <div class="stat">
          <div class="stat-k">我的编号</div>
          <div class="stat-v"><span data-copy="<?= h(pad4((string)$me['id'])) ?>"><?= h(pad4((string)$me['id'])) ?></span></div>
        </div>
      </div>

      <form method="post" class="mt">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="transfer">

        <label for="toInput">收款人（编号或用户名）</label>
        <input id="toInput" name="to" required autocomplete="off" placeholder="例如 0170 或 mountain">

        <?php if (!empty($recentHandles)): ?>
          <div class="fill-chips">
            <?php foreach ($recentHandles as $handle => $no): ?>
              <button type="button" class="fill-chip" data-fill="<?= h($handle) ?>">
                <?= h($handle) ?> · <?= h($no) ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <label for="amountInput">转账金额（MTD，最多三位小数）</label>
        <input id="amountInput" name="amount" required inputmode="decimal" autocomplete="off" placeholder="例如 10 或 50.500">
        <div class="hint" id="feeTip"
             data-rate="<?= h((string)TRANSFER_FEE_RATE) ?>"
             data-min="<?= h(money(TRANSFER_FEE_MIN)) ?>">
          单笔超过 <?= h(money(TRANSFER_FEE_MIN)) ?> MTD 收取 <?= h((string)(int)round(TRANSFER_FEE_RATE * 100)) ?>% 手续费。
        </div>

        <label for="noteInput">备注（可选，最多 60 字）</label>
        <input id="noteInput" name="note" maxlength="60" autocomplete="off" placeholder="例如：午饭 AA">

        <?php if (userHasPin($me)): ?>
          <?php $remain = pinLockRemain($me); ?>
          <label for="pinInput">6 位转账密码</label>
          <input id="pinInput" name="pay_pin" inputmode="numeric" maxlength="6" pattern="\d{6}"
                 autocomplete="off" placeholder="请输入 6 位数字密码" <?= $remain > 0 ? 'disabled' : 'required' ?>>
          <?php if ($remain > 0): ?>
            <div class="notice notice-warn mt">转账密码已锁定，请 <?= (int)$remain ?> 秒后再试。</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="notice notice-warn mt">
            你还没有设置 6 位转账密码，本次转账将直接扣款。
            <a href="<?= h(q(['page' => 'security'])) ?>">立即设置 →</a>
          </div>
        <?php endif; ?>

        <div class="mt">
          <button type="submit" class="btn btn-block"><?= icon('transfer') ?> 确认转账</button>
        </div>
      </form>
    </section>

    <section class="panel">
      <h2><?= icon('shield') ?> 转账规则</h2>
      <ul class="muted small" style="margin:0;padding-left:20px">
        <li>不能给自己转账，收款账户必须处于正常状态。</li>
        <li>单笔超过 <?= h(money(TRANSFER_FEE_MIN)) ?> MTD：手续费 = 金额 × <?= h((string)(int)round(TRANSFER_FEE_RATE * 100)) ?>%，向上取整到 0.001 MTD。</li>
        <li>手续费进入资金池，收款方实收全额转账金额。</li>
        <li>转账密码连续输错 <?= PIN_MAX_FAIL ?> 次会锁定 <?= (int)(PIN_LOCK_SEC / 60) ?> 分钟。</li>
      </ul>
    </section>
    <script>
      document.querySelectorAll('.fill-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
          var input = document.getElementById('toInput');
          if (input) { input.value = chip.getAttribute('data-fill') || ''; input.focus(); }
        });
      });
    </script>
    <?php
    renderFoot();
    exit;
}

if ($page === 'checkin') {
    $me = requireLogin($data);
    $today = date('Y-m-d');
    $already = ((string)($me['last_checkin'] ?? '') === $today);

    renderHead('每日签到', $me, $flashMsg, 'checkin');
    ?>
    <section class="panel center">
      <h2 style="justify-content:center"><?= icon('checkin') ?> 每日签到</h2>

      <?php if ($already): ?>
        <div class="conv-out">
          <div class="co-v">今日已签到 ✅</div>
          <div class="co-sub">明天再来，连续签到才有积累。</div>
        </div>
      <?php else: ?>
        <p class="muted">签到一次可获得 <b><?= h(money(DAILY_BONUS)) ?> MTD</b>，每天限一次。</p>
        <form method="post" class="mt">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="checkin">
          <button type="submit" class="btn btn-block"><?= icon('checkin') ?> 立即签到，领取 <?= h(money(DAILY_BONUS)) ?> MTD</button>
        </form>
      <?php endif; ?>

      <div class="grid g3 mt">
        <div class="stat"><div class="stat-k">我的余额</div><div class="stat-v"><?= h(money((int)$me['balance'])) ?> <small>MTD</small></div></div>
        <div class="stat"><div class="stat-k">上次签到</div><div class="stat-v" style="font-size:16px"><?= h((string)($me['last_checkin'] ?? '从未')) ?></div></div>
        <div class="stat"><div class="stat-k">今日签到人数</div><div class="stat-v"><?= countTodayCheckins($data) ?> <small>人</small></div></div>
      </div>

      <p class="hint mt">签到奖励从资金池发放，资金池余额：<?= h(money(poolOf($data))) ?> MTD。</p>
    </section>
    <?php
    renderFoot();
    exit;
}

if ($page === 'quiz') {
    $me = requireLogin($data);
    $today = date('Y-m-d');

    if (($me['role'] ?? '') === 'admin') {
        flash('info', '管理员账户不参与答题，请使用管理后台。');
        redirect(q(['page' => 'admin']));
    }

    $saved = $_SESSION['quiz_question'] ?? null;

    if (!is_array($saved) || (time() - (int)($saved['time'] ?? 0)) > 600) {
        $saved = quizNewQuestion();
    }

    $done = ((string)($me['last_quiz_date'] ?? '') === $today) ? (int)$me['quiz_count'] : 0;
    $left = QUIZ_DAILY_LIMIT > 0 ? max(0, QUIZ_DAILY_LIMIT - $done) : -1;

    renderHead('答题奖励', $me, $flashMsg, 'quiz');
    ?>
    <section class="panel center">
      <h2 style="justify-content:center"><?= icon('quiz') ?> 乘法答题</h2>

      <?php if ($left === 0): ?>
        <div class="conv-out">
          <div class="co-v">今日额度已用完</div>
          <div class="co-sub">每天最多 <?= QUIZ_DAILY_LIMIT ?> 题，明天再来。</div>
        </div>
      <?php else: ?>
        <p class="muted">答对一题得 <b><?= h(money(QUIZ_BONUS)) ?> MTD</b><?= $left > 0 ? '，今日还剩 <b>' . (int)$left . '</b> 题' : '' ?>。</p>

        <div class="conv-out">
          <div class="co-sub">请计算</div>
          <div class="co-v"><?= h((string)$saved['q']) ?> = ?</div>
        </div>

        <form method="post" class="mt">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="quiz">
          <input type="hidden" name="question" value="<?= h((string)$saved['q']) ?>">
          <label for="answer">你的答案</label>
          <input id="answer" name="answer" inputmode="numeric" pattern="\d{1,4}" required autocomplete="off"
                 placeholder="填写计算结果" autofocus>
          <div class="mt">
            <button type="submit" class="btn btn-block"><?= icon('quiz') ?> 提交答案</button>
          </div>
        </form>
      <?php endif; ?>

      <div class="grid g3 mt">
        <div class="stat"><div class="stat-k">今日已答</div><div class="stat-v"><?= $done ?> <small>题</small></div></div>
        <div class="stat"><div class="stat-k">每题奖励</div><div class="stat-v"><?= h(money(QUIZ_BONUS)) ?></div></div>
        <div class="stat"><div class="stat-k">我的余额</div><div class="stat-v"><?= h(money((int)$me['balance'])) ?> <small>MTD</small></div></div>
      </div>
      <p class="hint mt">题目由服务器生成，10 分钟未提交会自动作废。</p>
    </section>
    <?php
    renderFoot();
    exit;
}

if ($page === 'bills') {
    $me = requireLogin($data);
    $mine = array_values(array_filter(
        $data['transactions'],
        function (array $txn) use ($me): bool {
            return idKey((string)($txn['from_id'] ?? '')) === idKey((string)$me['id'])
                || idKey((string)($txn['to_id'] ?? '')) === idKey((string)$me['id']);
        }
    ));

    $ordered = array_reverse($mine);
    $slice   = pageSlice($ordered, (int)($_GET['p'] ?? 1), PER_PAGE);

    $incomeSum  = 0;
    $outcomeSum = 0;

    foreach ($mine as $txn) {
        if (idKey((string)($txn['to_id'] ?? '')) === idKey((string)$me['id'])) {
            $incomeSum += (int)($txn['amount'] ?? 0);
        }

        if (idKey((string)($txn['from_id'] ?? '')) === idKey((string)$me['id'])) {
            $outcomeSum += (int)($txn['amount'] ?? 0) + (int)($txn['fee'] ?? 0);
        }
    }

    renderHead('我的账单', $me, $flashMsg, 'bills');
    ?>
    <section class="grid g4">
      <div class="stat"><div class="stat-k">当前余额</div><div class="stat-v"><?= h(money((int)$me['balance'])) ?> <small>MTD</small></div></div>
      <div class="stat"><div class="stat-k">累计收入</div><div class="stat-v pos">+<?= h(money($incomeSum)) ?></div></div>
      <div class="stat"><div class="stat-k">累计支出</div><div class="stat-v neg">-<?= h(money($outcomeSum)) ?></div></div>
      <div class="stat"><div class="stat-k">账单条数</div><div class="stat-v"><?= count($mine) ?> <small>笔</small></div></div>
    </section>

    <section class="panel">
      <h2><?= icon('bill') ?> 全部账单</h2>
      <?php renderTxnTable($slice['items'], $data, (string)$me['id']); ?>
      <?php renderPager($slice, ['page' => 'bills']); ?>
    </section>
    <?php
    renderFoot();
    exit;
}

if ($page === 'security') {
    $me = requireLogin($data);
    $hasPin = userHasPin($me);
    $remain = pinLockRemain($me);

    renderHead('安全设置', $me, $flashMsg, 'security');
    ?>
    <section class="panel">
      <h2><?= icon('user') ?> 账户信息</h2>
      <div class="grid g4">
        <div class="stat"><div class="stat-k">用户名</div><div class="stat-v" style="font-size:17px"><?= h((string)$me['username']) ?></div></div>
        <div class="stat"><div class="stat-k">4 位编号</div><div class="stat-v" style="font-size:17px"><?= h(pad4((string)$me['id'])) ?></div></div>
        <div class="stat"><div class="stat-k">当前余额</div><div class="stat-v"><?= h(money((int)$me['balance'])) ?> <small>MTD</small></div></div>
        <div class="stat"><div class="stat-k">注册时间</div><div class="stat-v" style="font-size:15px"><?= h((string)$me['created_at']) ?></div></div>
      </div>
      <div class="hint">
        身份：<?= ($me['role'] ?? '') === 'admin' ? '管理员' : '普通会员' ?>
        ｜最近签到：<?= h((string)($me['last_checkin'] ?? '从未')) ?>
        ｜转账密码：<?= $hasPin ? '已设置' : '未设置' ?>
      </div>
    </section>

    <div class="grid g2">
      <section class="panel">
        <h2><?= icon('lock') ?> 修改登录密码</h2>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="password_change">
          <label for="current_password">当前密码</label>
          <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
          <label for="new_password">新密码（至少 6 位）</label>
          <input id="new_password" name="new_password" type="password" required minlength="6" autocomplete="new-password">
          <label for="new_password_again">再输一次新密码</label>
          <input id="new_password_again" name="new_password_again" type="password" required minlength="6" autocomplete="new-password">
          <div class="mt">
            <button type="submit" class="btn btn-block">保存新密码</button>
          </div>
        </form>
        <p class="hint">首次部署时，管理员请务必修改默认密码。</p>
      </section>

      <section class="panel">
        <h2><?= icon('shield') ?> 转账密码（6 位数字）</h2>
        <p class="muted small">
          设置后，每次转账都需要输入这 6 位密码；连续输错 <?= PIN_MAX_FAIL ?> 次会锁定 <?= (int)(PIN_LOCK_SEC / 60) ?> 分钟。
          不设置则可直接转账。
        </p>

        <?php if ($remain > 0): ?>
          <div class="notice notice-warn">当前处于锁定状态，请 <?= (int)$remain ?> 秒后再操作。</div>
        <?php endif; ?>

        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="pin_set">
          <input type="hidden" name="mode" value="set">
          <label for="pin">新的 6 位转账密码</label>
          <input id="pin" name="pin" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="off" placeholder="6 位数字">
          <label for="pin_again">再输一次</label>
          <input id="pin_again" name="pin_again" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="off" placeholder="6 位数字">
          <label for="pin_password">登录密码（用于确认身份）</label>
          <input id="pin_password" name="current_password" type="password" required autocomplete="current-password">
          <div class="mt">
            <button type="submit" class="btn btn-block"><?= $hasPin ? '更新转账密码' : '设置转账密码' ?></button>
          </div>
        </form>

        <?php if ($hasPin): ?>
          <hr class="sep">
          <form method="post" onsubmit="return confirm('确定要关闭转账密码吗？之后转账将不再验证。')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="pin_set">
            <input type="hidden" name="mode" value="remove">
            <label for="remove_password">登录密码（用于确认身份）</label>
            <input id="remove_password" name="current_password" type="password" required autocomplete="current-password">
            <div class="mt">
              <button type="submit" class="btn btn-ghost btn-block">关闭转账密码</button>
            </div>
          </form>
        <?php endif; ?>
      </section>
    </div>
    <?php
    renderFoot();
    exit;
}

/* ============================================================================
 * 8.5 管理后台
 * ==========================================================================*/

if ($page === 'admin') {
    $admin = requireAdmin($data);

    $tab = (string)($_GET['tab'] ?? 'users');

    if (!in_array($tab, ['users', 'rates', 'bills'], true)) {
        $tab = 'users';
    }

    renderHead('管理后台', $admin, $flashMsg, 'admin');
    ?>
    <section class="grid g4">
      <div class="stat"><div class="stat-k">资金池</div><div class="stat-v"><?= h(money(poolOf($data))) ?> <small>MTD</small></div></div>
      <div class="stat"><div class="stat-k">有效账户</div><div class="stat-v"><?= countActiveUsers($data) ?> <small>个</small></div></div>
      <div class="stat"><div class="stat-k">累计账单</div><div class="stat-v"><?= count($data['transactions']) ?> <small>笔</small></div></div>
      <div class="stat"><div class="stat-k">今日签到</div><div class="stat-v"><?= countTodayCheckins($data) ?> <small>人</small></div></div>
    </section>

    <section class="panel">
      <h2><?= icon('admin') ?> 后台导航</h2>
      <div class="btn-row">
        <a class="btn <?= $tab === 'users' ? '' : 'btn-ghost' ?>" href="<?= h(q(['page' => 'admin', 'tab' => 'users'])) ?>">用户与余额</a>
        <a class="btn <?= $tab === 'rates' ? '' : 'btn-ghost' ?>" href="<?= h(q(['page' => 'admin', 'tab' => 'rates'])) ?>">汇率设置</a>
        <a class="btn <?= $tab === 'bills' ? '' : 'btn-ghost' ?>" href="<?= h(q(['page' => 'admin', 'tab' => 'bills'])) ?>">全站账单</a>
      </div>
    </section>

    <?php if ($tab === 'rates'): ?>
      <?php
      $codes = currencyLabels();
      ?>
      <section class="panel">
        <h2><?= icon('rate') ?> 汇率设置</h2>
        <p class="muted small">
          填写「1 单位外币 = 多少人民币」，以及「1 元人民币 = 多少 MTD」。保存后全站换算立即生效。
        </p>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="admin_rates">
          <div class="grid g3">
            <?php foreach ($codes as $code => $label): ?>
              <?php if ($code === 'MTD') { continue; } ?>
              <div>
                <label for="rate_<?= h($code) ?>">1 <?= h($code) ?>（<?= h($label) ?>） = ? 元人民币</label>
                <input id="rate_<?= h($code) ?>" name="rates[<?= h($code) ?>]" inputmode="decimal"
                       value="<?= h((string)$rates[$code]) ?>" required>
              </div>
            <?php endforeach; ?>
            <div>
              <label for="mtd_per_cny">1 元人民币 = ? MTD</label>
              <input id="mtd_per_cny" name="mtd_per_cny" inputmode="decimal" value="<?= h((string)$mtdPerCny) ?>" required>
            </div>
          </div>
          <div class="mt">
            <button type="submit" class="btn">保存汇率</button>
          </div>
        </form>
        <div class="hint">
          当前显示：1 元人民币 = <?= h(formatNumber($mtdPerCny)) ?> MTD；
          1 MTD ≈ <?= h(formatNumber(1 / max(0.000001, $mtdPerCny))) ?> 元人民币。
        </div>
      </section>
    <?php endif; ?>

    <?php if ($tab === 'users'): ?>
      <section class="panel">
        <h2><?= icon('gift') ?> 资金池调整</h2>
        <p class="muted small">
          签到、答题、注册赠送的奖励都从资金池发放；转账手续费与回收的余额会回到资金池。
          当前资金池：<b><?= h(money(poolOf($data))) ?> MTD</b>（初始 <?= h(money(INITIAL_POOL)) ?> MTD）。
        </p>
        <form method="post" class="field-row" style="align-items:flex-end">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="admin_pool">
          <div>
            <label for="pool_amount">金额（MTD）</label>
            <input id="pool_amount" name="amount" inputmode="decimal" required placeholder="例如 1000">
          </div>
          <div>
            <label for="pool_mode">操作</label>
            <select id="pool_mode" name="mode">
              <option value="add">补充资金池</option>
              <option value="remove">减少资金池</option>
            </select>
          </div>
          <div>
            <button type="submit" class="btn btn-gold">提交</button>
          </div>
        </form>
      </section>

      <section class="panel">
        <h2><?= icon('user') ?> 用户账户（<?= countActiveUsers($data) ?> 个有效）</h2>
        <?php foreach ($data['users'] as $account): ?>
          <?php if (($account['role'] ?? '') === 'admin') { continue; } ?>
          <?php $active = (($account['active'] ?? false) === true); ?>
          <div class="account">
            <div class="account-top">
              <div>
                <div class="account-name">
                  <?= h((string)$account['username']) ?>
                  <span class="badge">编号 <?= h(pad4((string)$account['id'])) ?></span>
                  <?php if (!$active): ?><span class="badge" style="background:rgba(255,255,255,.07);border-color:var(--line);color:var(--muted)">已注销</span><?php endif; ?>
                  <span class="badge" style="background:rgba(52,211,153,.14);border-color:rgba(52,211,153,.35);color:#a7f3d0">
                    <?= userHasPin($account) ? '已设转账密码' : '未设转账密码' ?>
                  </span>
                </div>
                <div class="muted small">
                  注册：<?= h((string)$account['created_at']) ?>
                  ｜最近签到：<?= h((string)($account['last_checkin'] ?? '从未')) ?>
                  <?php if (!$active): ?>｜注销于 <?= h((string)($account['deleted_at'] ?? '-')) ?><?php endif; ?>
                </div>
              </div>
              <div class="account-bal"><?= h(money((int)($account['balance'] ?? 0))) ?> MTD</div>
            </div>

            <?php if ($active): ?>
              <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="admin_adjust">
                <input type="hidden" name="target_id" value="<?= h((string)$account['id']) ?>">
                <select name="mode">
                  <option value="add">增加余额</option>
                  <option value="remove">扣除余额</option>
                </select>
                <input name="amount" inputmode="decimal" placeholder="金额 MTD" required>
                <input name="reason" maxlength="60" placeholder="原因（可选）">
                <button type="submit" class="btn btn-sm">提交调整</button>
              </form>

              <form method="post" class="mt" style="margin-top:10px"
                    onsubmit="return confirm('确定注销该账户？余额将回收到资金池。')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="admin_delete">
                <input type="hidden" name="target_id" value="<?= h((string)$account['id']) ?>">
                <button type="submit" class="btn btn-red btn-sm">注销账户</button>
              </form>
            <?php else: ?>
              <form method="post" class="mt" style="margin-top:10px">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="admin_activate">
                <input type="hidden" name="target_id" value="<?= h((string)$account['id']) ?>">
                <button type="submit" class="btn btn-ghost btn-sm">恢复账户</button>
              </form>
            <?php endif; ?>

            <form method="post" class="mt" style="margin-top:10px"
                  onsubmit="return confirm('确定重置该用户的转账密码？')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="admin_reset_pin">
              <input type="hidden" name="target_id" value="<?= h((string)$account['id']) ?>">
              <button type="submit" class="btn btn-ghost btn-sm">重置转账密码</button>
            </form>
          </div>
        <?php endforeach; ?>

        <?php if (count($data['users']) <= 1): ?>
          <p class="muted">目前还没有普通用户。</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($tab === 'bills'): ?>
      <?php
      $allSlice = pageSlice(array_reverse($data['transactions']), (int)($_GET['p'] ?? 1), 40);
      ?>
      <section class="panel">
        <h2><?= icon('bill') ?> 全站账单</h2>
        <?php renderTxnTable($allSlice['items'], $data, ''); ?>
        <?php renderPager($allSlice, ['page' => 'admin', 'tab' => 'bills']); ?>
      </section>
    <?php endif; ?>
    <?php
    renderFoot();
    exit;
}

/* 兜底 */
redirect(q(['page' => 'home']));
