<?php
declare(strict_types=1);
session_start();

$dbFile = __DIR__ . DIRECTORY_SEPARATOR . 'ritahcakes.sqlite';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL CHECK(role IN ("admin", "receptionist")),
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cake_price_tier TEXT NOT NULL,
        custom_price_description TEXT DEFAULT "",
        shape_details TEXT DEFAULT "",
        amount_to_pay REAL NOT NULL,
        amount_paid REAL NOT NULL,
        balance REAL NOT NULL,
        pickup_date TEXT NOT NULL,
        owner_name TEXT NOT NULL,
        cake_text TEXT DEFAULT "",
        design_color TEXT DEFAULT "",
        other_details TEXT DEFAULT "",
        status TEXT NOT NULL DEFAULT "pending",
        created_by_user_id INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        received_at TEXT DEFAULT NULL
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        expense_date TEXT NOT NULL,
        category TEXT NOT NULL,
        description TEXT DEFAULT "",
        amount REAL NOT NULL,
        created_by_user_id INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'
);

/**
 * Add a missing column to an existing SQLite table.
 * This keeps older databases compatible after new releases.
 */
function ensureColumn(PDO $pdo, string $tableName, string $columnName, string $definition): void
{
    $columnsStmt = $pdo->query('PRAGMA table_info(' . $tableName . ')');
    $columns = $columnsStmt ? $columnsStmt->fetchAll() : [];
    foreach ($columns as $column) {
        if (isset($column['name']) && $column['name'] === $columnName) {
            return;
        }
    }

    $pdo->exec(
        'ALTER TABLE ' . $tableName . ' ADD COLUMN ' . $columnName . ' ' . $definition
    );
}

// Backward-compatible schema upgrades for old database files.
ensureColumn($pdo, 'orders', 'created_by_user_id', 'INTEGER DEFAULT NULL');

// Seed requested default admin once.
$adminUsername = 'tonnyblair';
$adminPassword = 'Blairtonny@1000';
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
$stmt->execute([':username' => $adminUsername]);
if (!$stmt->fetch()) {
    $seed = $pdo->prepare(
        'INSERT INTO users (username, password_hash, role)
         VALUES (:username, :password_hash, "admin")'
    );
    $seed->execute([
        ':username' => $adminUsername,
        ':password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
    ]);
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function isAdmin(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

$errors = [];
$success = '';
$activeTab = $_GET['tab'] ?? 'enter';
$cashflowStartDate = $_GET['cashflow_start'] ?? date('Y-m-01');
$cashflowEndDate = $_GET['cashflow_end'] ?? date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        $loginStmt = $pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $loginStmt->execute([':username' => $username]);
        $user = $loginStmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $success = 'Welcome, ' . $user['username'] . '.';
            $activeTab = 'enter';
        } else {
            $errors[] = 'Invalid username or password.';
        }
    }

    if ($action === 'logout') {
        session_unset();
        session_destroy();
        header('Location: index.php');
        exit;
    }

    if (isset($_SESSION['user_id'])) {
        if ($action === 'create_order') {
            $cakePriceTier = trim($_POST['cake_price_tier'] ?? '');
            $customPriceDescription = trim($_POST['custom_price_description'] ?? '');
            $shapeDetails = trim($_POST['shape_details'] ?? '');
            $amountToPay = (float)($_POST['amount_to_pay'] ?? 0);
            $amountPaid = (float)($_POST['amount_paid'] ?? 0);
            $pickupDate = trim($_POST['pickup_date'] ?? '');
            $ownerName = trim($_POST['owner_name'] ?? '');
            $cakeText = trim($_POST['cake_text'] ?? '');
            $designColor = trim($_POST['design_color'] ?? '');
            $otherDetails = trim($_POST['other_details'] ?? '');

            if ($cakePriceTier === '') {
                $errors[] = 'Cake size/amount tier is required.';
            }
            if ($amountToPay <= 0) {
                $errors[] = 'Amount to be paid must be greater than 0.';
            }
            if ($amountPaid < 0) {
                $errors[] = 'Amount paid cannot be negative.';
            }
            if ($pickupDate === '') {
                $errors[] = 'Pickup date is required.';
            }
            if ($ownerName === '') {
                $errors[] = 'Owner name is required.';
            }
            if ($cakePriceTier === '60k-with-shapes' && $shapeDetails === '') {
                $errors[] = 'Please provide shape details for the 60k with shapes option.';
            }
            if ($cakePriceTier === 'more' && $customPriceDescription === '') {
                $errors[] = 'Please describe the custom price details for "More".';
            }

            $balance = $amountToPay - $amountPaid;
            if ($balance < 0) {
                $errors[] = 'Amount paid cannot be more than amount to be paid.';
            }

            if (!$errors) {
                $stmt = $pdo->prepare(
                    'INSERT INTO orders
                    (
                        cake_price_tier, custom_price_description, shape_details,
                        amount_to_pay, amount_paid, balance, pickup_date, owner_name,
                        cake_text, design_color, other_details, status, created_by_user_id
                    )
                    VALUES
                    (
                        :cake_price_tier, :custom_price_description, :shape_details,
                        :amount_to_pay, :amount_paid, :balance, :pickup_date, :owner_name,
                        :cake_text, :design_color, :other_details, "pending", :created_by_user_id
                    )'
                );

                $stmt->execute([
                    ':cake_price_tier' => $cakePriceTier,
                    ':custom_price_description' => $customPriceDescription,
                    ':shape_details' => $shapeDetails,
                    ':amount_to_pay' => $amountToPay,
                    ':amount_paid' => $amountPaid,
                    ':balance' => $balance,
                    ':pickup_date' => $pickupDate,
                    ':owner_name' => $ownerName,
                    ':cake_text' => $cakeText,
                    ':design_color' => $designColor,
                    ':other_details' => $otherDetails,
                    ':created_by_user_id' => (int)$_SESSION['user_id'],
                ]);

                $success = 'Order saved successfully.';
                $activeTab = 'pending';
            } else {
                $activeTab = 'enter';
            }
        }

        if ($action === 'mark_received') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            if ($orderId > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE orders
                     SET status = "received", received_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $stmt->execute([':id' => $orderId]);
                $success = 'Order marked as received.';
                $activeTab = 'received';
            }
        }

        if ($action === 'delete_order') {
            if (!isAdmin()) {
                $errors[] = 'Only admin can delete order information.';
            } else {
                $orderId = (int)($_POST['order_id'] ?? 0);
                if ($orderId > 0) {
                    $stmt = $pdo->prepare('DELETE FROM orders WHERE id = :id');
                    $stmt->execute([':id' => $orderId]);
                    $success = 'Order deleted.';
                }
            }
        }

        if ($action === 'add_user') {
            if (!isAdmin()) {
                $errors[] = 'Only admin can add users.';
            } else {
                $newUsername = trim($_POST['new_username'] ?? '');
                $newPassword = (string)($_POST['new_password'] ?? '');
                $newRole = trim($_POST['new_role'] ?? '');

                if ($newUsername === '' || $newPassword === '' || $newRole === '') {
                    $errors[] = 'Username, password and role are required for new account.';
                } elseif (!in_array($newRole, ['admin', 'receptionist'], true)) {
                    $errors[] = 'Invalid user role.';
                } else {
                    try {
                        $stmt = $pdo->prepare(
                            'INSERT INTO users (username, password_hash, role)
                             VALUES (:username, :password_hash, :role)'
                        );
                        $stmt->execute([
                            ':username' => $newUsername,
                            ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                            ':role' => $newRole,
                        ]);
                        $success = 'User account created.';
                    } catch (Throwable $e) {
                        $errors[] = 'Username already exists.';
                    }
                }

                $activeTab = 'users';
            }
        }

        if ($action === 'delete_user') {
            if (!isAdmin()) {
                $errors[] = 'Only admin can delete users.';
            } else {
                $userId = (int)($_POST['user_id'] ?? 0);
                $currentUserId = (int)$_SESSION['user_id'];
                if ($userId === $currentUserId) {
                    $errors[] = 'You cannot delete your own active account.';
                } else {
                    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                    $stmt->execute([':id' => $userId]);
                    $success = 'User account deleted.';
                }
                $activeTab = 'users';
            }
        }

        if ($action === 'add_expense') {
            if (!isAdmin()) {
                $errors[] = 'Only admin can add expenses.';
            } else {
                $expenseDate = trim($_POST['expense_date'] ?? '');
                $expenseCategory = trim($_POST['expense_category'] ?? '');
                $expenseDescription = trim($_POST['expense_description'] ?? '');
                $expenseAmount = (float)($_POST['expense_amount'] ?? 0);

                if ($expenseDate === '' || $expenseCategory === '') {
                    $errors[] = 'Expense date and category are required.';
                }
                if ($expenseAmount <= 0) {
                    $errors[] = 'Expense amount must be greater than 0.';
                }

                if (!$errors) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO expenses
                        (expense_date, category, description, amount, created_by_user_id)
                        VALUES
                        (:expense_date, :category, :description, :amount, :created_by_user_id)'
                    );
                    $stmt->execute([
                        ':expense_date' => $expenseDate,
                        ':category' => $expenseCategory,
                        ':description' => $expenseDescription,
                        ':amount' => $expenseAmount,
                        ':created_by_user_id' => (int)$_SESSION['user_id'],
                    ]);
                    $success = 'Expense recorded.';
                }
                $activeTab = 'cashflow';
            }
        }

        if ($action === 'delete_expense') {
            if (!isAdmin()) {
                $errors[] = 'Only admin can delete expenses.';
            } else {
                $expenseId = (int)($_POST['expense_id'] ?? 0);
                if ($expenseId > 0) {
                    $stmt = $pdo->prepare('DELETE FROM expenses WHERE id = :id');
                    $stmt->execute([':id' => $expenseId]);
                    $success = 'Expense deleted.';
                }
                $activeTab = 'cashflow';
            }
        }
    }
}

$isLoggedIn = isset($_SESSION['user_id']);
$isAdminUser = isAdmin();

if ($isLoggedIn && !$isAdminUser && in_array($activeTab, ['cashflow', 'users'], true)) {
    $activeTab = 'enter';
}

$pendingDates = [];
$pendingOrders = [];
$receivedDates = [];
$receivedOrders = [];
$dailyCashFlowRows = [];
$recentExpenses = [];
$financeSummary = [
    'cash_in' => 0.0,
    'cash_out' => 0.0,
    'net' => 0.0,
    'pending_balances' => 0.0,
];
$allUsers = [];
$pendingSelectedDate = $_GET['pending_date'] ?? '';
$receivedSelectedDate = $_GET['received_date'] ?? '';

if ($isLoggedIn) {
    $pendingDates = $pdo->query(
        'SELECT pickup_date
         FROM orders
         WHERE status = "pending"
         GROUP BY pickup_date
         ORDER BY pickup_date ASC'
    )->fetchAll();

    if ($pendingSelectedDate === '' && count($pendingDates) > 0) {
        $pendingSelectedDate = $pendingDates[0]['pickup_date'];
    }

    if ($pendingSelectedDate !== '') {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM orders
             WHERE status = "pending" AND pickup_date = :pickup_date
             ORDER BY created_at ASC'
        );
        $stmt->execute([':pickup_date' => $pendingSelectedDate]);
        $pendingOrders = $stmt->fetchAll();
    }

    $receivedDates = $pdo->query(
        'SELECT pickup_date
         FROM orders
         WHERE status = "received"
         GROUP BY pickup_date
         ORDER BY pickup_date ASC'
    )->fetchAll();

    if ($receivedSelectedDate === '' && count($receivedDates) > 0) {
        $receivedSelectedDate = $receivedDates[0]['pickup_date'];
    }

    if ($receivedSelectedDate !== '') {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM orders
             WHERE status = "received" AND pickup_date = :pickup_date
             ORDER BY received_at ASC'
        );
        $stmt->execute([':pickup_date' => $receivedSelectedDate]);
        $receivedOrders = $stmt->fetchAll();
    }

    if ($isAdminUser) {
        $cashInStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount_paid), 0) AS total_cash_in
             FROM orders
             WHERE status = "received"
               AND DATE(received_at) BETWEEN :start_date AND :end_date'
        );
        $cashInStmt->execute([
            ':start_date' => $cashflowStartDate,
            ':end_date' => $cashflowEndDate,
        ]);
        $cashIn = (float)$cashInStmt->fetch()['total_cash_in'];

        $cashOutStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS total_cash_out
             FROM expenses
             WHERE expense_date BETWEEN :start_date AND :end_date'
        );
        $cashOutStmt->execute([
            ':start_date' => $cashflowStartDate,
            ':end_date' => $cashflowEndDate,
        ]);
        $cashOut = (float)$cashOutStmt->fetch()['total_cash_out'];

        $pendingBalanceStmt = $pdo->query(
            'SELECT COALESCE(SUM(balance), 0) AS pending_balances
             FROM orders
             WHERE status = "pending"'
        );
        $pendingBalance = (float)$pendingBalanceStmt->fetch()['pending_balances'];

        $financeSummary = [
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'net' => $cashIn - $cashOut,
            'pending_balances' => $pendingBalance,
        ];

        $inByDayStmt = $pdo->prepare(
            'SELECT DATE(received_at) AS day, COALESCE(SUM(amount_paid), 0) AS cash_in
             FROM orders
             WHERE status = "received"
               AND DATE(received_at) BETWEEN :start_date AND :end_date
             GROUP BY DATE(received_at)'
        );
        $inByDayStmt->execute([
            ':start_date' => $cashflowStartDate,
            ':end_date' => $cashflowEndDate,
        ]);
        $inByDayRows = $inByDayStmt->fetchAll();

        $outByDayStmt = $pdo->prepare(
            'SELECT expense_date AS day, COALESCE(SUM(amount), 0) AS cash_out
             FROM expenses
             WHERE expense_date BETWEEN :start_date AND :end_date
             GROUP BY expense_date'
        );
        $outByDayStmt->execute([
            ':start_date' => $cashflowStartDate,
            ':end_date' => $cashflowEndDate,
        ]);
        $outByDayRows = $outByDayStmt->fetchAll();

        $dailyMap = [];
        foreach ($inByDayRows as $row) {
            $day = (string)$row['day'];
            $dailyMap[$day] = [
                'day' => $day,
                'cash_in' => (float)$row['cash_in'],
                'cash_out' => 0.0,
                'net' => (float)$row['cash_in'],
            ];
        }
        foreach ($outByDayRows as $row) {
            $day = (string)$row['day'];
            if (!isset($dailyMap[$day])) {
                $dailyMap[$day] = [
                    'day' => $day,
                    'cash_in' => 0.0,
                    'cash_out' => 0.0,
                    'net' => 0.0,
                ];
            }
            $dailyMap[$day]['cash_out'] += (float)$row['cash_out'];
            $dailyMap[$day]['net'] = $dailyMap[$day]['cash_in'] - $dailyMap[$day]['cash_out'];
        }
        krsort($dailyMap);
        $dailyCashFlowRows = array_values($dailyMap);

        $recentExpensesStmt = $pdo->prepare(
            'SELECT id, expense_date, category, description, amount
             FROM expenses
             WHERE expense_date BETWEEN :start_date AND :end_date
             ORDER BY expense_date DESC, id DESC'
        );
        $recentExpensesStmt->execute([
            ':start_date' => $cashflowStartDate,
            ':end_date' => $cashflowEndDate,
        ]);
        $recentExpenses = $recentExpensesStmt->fetchAll();

        $allUsers = $pdo->query(
            'SELECT id, username, role, created_at
             FROM users
             ORDER BY role ASC, created_at DESC'
        )->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RITAHCAKES BAKERY Orders</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f8f7f4; color: #222; }
        .container { max-width: 1100px; margin: 20px auto; padding: 0 14px; }
        h1 { margin: 0 0 16px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .tab {
            text-decoration: none; padding: 10px 14px; border-radius: 8px;
            background: #fff; color: #7b3f00; border: 1px solid #e8d9c7; font-weight: 600;
        }
        .tab.active { background: #7b3f00; color: #fff; }
        .card { background: #fff; border: 1px solid #e8d9c7; border-radius: 10px; padding: 14px; margin-bottom: 16px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px; }
        label { display: block; font-size: 14px; margin-bottom: 5px; }
        input, select, textarea {
            width: 100%; padding: 8px; border: 1px solid #d8d8d8; border-radius: 6px;
            font-size: 14px; box-sizing: border-box;
        }
        textarea { min-height: 80px; }
        .btn {
            background: #7b3f00; color: #fff; border: none; border-radius: 6px;
            padding: 9px 14px; cursor: pointer; font-weight: 600;
        }
        .btn.secondary { background: #22543d; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #eee; padding: 8px; font-size: 14px; text-align: left; vertical-align: top; }
        th { background: #faf6f2; }
        .alerts { margin-bottom: 12px; }
        .error { color: #a00; margin: 0 0 6px; }
        .success { color: #0a6; margin: 0; }
        .muted { color: #777; font-size: 13px; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .danger { background: #b91c1c; }
        .shape-builder { border: 1px solid #e8d9c7; border-radius: 8px; padding: 10px; background: #faf6f2; }
        .shape-preview-stack { display: flex; flex-direction: column; align-items: center; gap: 4px; margin-top: 10px; }
        .shape-illustration { display: flex; flex-direction: column; align-items: center; }
        .shape-illustration-tier { border: 2px solid #3b6ea3; background: #5f95c8; height: 44px; }
        .shape-price-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; margin-top: 10px; }
        .shape-price-card { border: 1px solid #ddd; border-radius: 8px; padding: 10px; background: #fff; }
        .shape-total { margin-top: 10px; font-weight: 700; }
    </style>
</head>
<body>
    <div class="container">
        <div class="topbar">
            <h1>RITAHCAKES BAKERY - Orders Dashboard</h1>
            <?php if ($isLoggedIn): ?>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="logout">
                    <span class="muted" style="margin-right:8px;">
                        Logged in as <?= h((string)$_SESSION['username']) ?> (<?= h((string)$_SESSION['role']) ?>)
                    </span>
                    <button class="btn" type="submit">Logout</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($errors || $success !== ''): ?>
            <div class="alerts card">
                <?php foreach ($errors as $error): ?>
                    <p class="error"><?= h($error) ?></p>
                <?php endforeach; ?>
                <?php if ($success !== ''): ?>
                    <p class="success"><?= h($success) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$isLoggedIn): ?>
            <div class="card" style="max-width: 500px; margin: 20px auto;">
                <h2>Login</h2>
                <p class="muted">Use your username and password to continue.</p>
                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <div style="margin-bottom: 10px;">
                        <label for="username">Username</label>
                        <input id="username" name="username" type="text" required>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" required>
                    </div>
                    <button class="btn" type="submit">Login</button>
                </form>
                <p class="muted" style="margin-top: 10px;">Default admin created: <strong>tonnyblair</strong></p>
            </div>
        <?php else: ?>
            <div class="tabs">
                <a class="tab <?= $activeTab === 'enter' ? 'active' : '' ?>" href="?tab=enter">Enter Order</a>
                <a class="tab <?= $activeTab === 'pending' ? 'active' : '' ?>" href="?tab=pending">Pending Orders</a>
                <a class="tab <?= $activeTab === 'received' ? 'active' : '' ?>" href="?tab=received">Orders Received</a>
                <?php if ($isAdminUser): ?>
                    <a class="tab <?= $activeTab === 'cashflow' ? 'active' : '' ?>" href="?tab=cashflow">Cash Flow</a>
                <?php endif; ?>
                <?php if ($isAdminUser): ?>
                    <a class="tab <?= $activeTab === 'users' ? 'active' : '' ?>" href="?tab=users">User Management</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isLoggedIn && $activeTab === 'enter'): ?>
            <div class="card">
                <h2>Enter New Cake Order</h2>
                <form method="post">
                    <input type="hidden" name="action" value="create_order">
                    <div class="grid">
                        <div>
                            <label for="cake_price_tier">Cake Size / Amount</label>
                            <input
                                id="cake_price_tier"
                                name="cake_price_tier"
                                type="text"
                                list="cake_price_tier_options"
                                placeholder="Select or type custom tier (e.g. 90k)"
                                required
                            >
                            <datalist id="cake_price_tier_options">
                                <option value="25k"></option>
                                <option value="35k"></option>
                                <option value="40k"></option>
                                <option value="50k"></option>
                                <option value="60k-with-shapes"></option>
                                <option value="70k"></option>
                                <option value="80k"></option>
                                <option value="100k"></option>
                                <option value="more"></option>
                            </datalist>
                        </div>
                        <div>
                            <label for="custom_price_description">More (Custom Price Description)</label>
                            <input id="custom_price_description" name="custom_price_description" type="text" placeholder="Explain custom amount (if More)">
                        </div>
                        <div>
                            <label for="shape_details">60k Shapes Details</label>
                            <input id="shape_details" name="shape_details" type="hidden">
                            <div class="shape-builder">
                                <div class="grid">
                                    <div>
                                        <label for="shape_type">Shape Type</label>
                                        <select id="shape_type">
                                            <option value="stacked-rectangle">Stacked Rectangle</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="shape_count">How many shapes?</label>
                                        <select id="shape_count">
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                            <option value="5">5</option>
                                        </select>
                                    </div>
                                </div>
                                <div id="shape_preview_stack" class="shape-preview-stack"></div>
                                <div id="shape_price_grid" class="shape-price-grid"></div>
                                <div id="shape_total" class="shape-total">Shapes Total: 0</div>
                                <p class="muted" style="margin: 8px 0 0;">
                                    Number of shapes means one stacked cake: smallest tier on top, bigger in middle, biggest at the bottom.
                                </p>
                            </div>
                        </div>
                        <div>
                            <label for="amount_to_pay">Amount to be Paid</label>
                            <input id="amount_to_pay" name="amount_to_pay" type="number" step="0.01" min="0" required>
                        </div>
                        <div>
                            <label for="amount_paid">Amount Paid</label>
                            <input id="amount_paid" name="amount_paid" type="number" step="0.01" min="0" required>
                        </div>
                        <div>
                            <label for="pickup_date">Date of Pickup</label>
                            <input id="pickup_date" name="pickup_date" type="date" required>
                        </div>
                        <div>
                            <label for="owner_name">Cake Owner Name</label>
                            <input id="owner_name" name="owner_name" type="text" required>
                        </div>
                        <div>
                            <label for="cake_text">What to Write on Cake</label>
                            <input id="cake_text" name="cake_text" type="text" placeholder="Birthday message, etc.">
                        </div>
                        <div>
                            <label for="design_color">Design and Color</label>
                            <input id="design_color" name="design_color" type="text" placeholder="Design style + color">
                        </div>
                    </div>
                    <div style="margin-top: 12px;">
                        <label for="other_details">Other Details</label>
                        <textarea id="other_details" name="other_details" placeholder="Any extra order notes"></textarea>
                    </div>
                    <div style="margin-top: 12px;">
                        <button class="btn" type="submit">Save Order</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($isLoggedIn && $activeTab === 'pending'): ?>
            <div class="card">
                <h2>Pending Orders by Pickup Date</h2>
                <form method="get" style="margin-bottom: 12px;">
                    <input type="hidden" name="tab" value="pending">
                    <label for="pending_date">Select Pickup Date</label>
                    <select id="pending_date" name="pending_date" onchange="this.form.submit()">
                        <?php if (!$pendingDates): ?>
                            <option value="">No pending dates</option>
                        <?php endif; ?>
                        <?php foreach ($pendingDates as $row): ?>
                            <option value="<?= h($row['pickup_date']) ?>" <?= $pendingSelectedDate === $row['pickup_date'] ? 'selected' : '' ?>>
                                <?= h($row['pickup_date']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if (!$pendingOrders): ?>
                    <p class="muted">No pending orders for selected date.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Owner</th>
                                <th>Cake Tier</th>
                                <th>Amounts</th>
                                <th>Cake Instructions</th>
                                <th>Other</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingOrders as $order): ?>
                                <tr>
                                    <td><?= h($order['owner_name']) ?></td>
                                    <td>
                                        <?= h($order['cake_price_tier']) ?><br>
                                        <?php if ($order['custom_price_description'] !== ''): ?>
                                            <small>More: <?= h($order['custom_price_description']) ?></small><br>
                                        <?php endif; ?>
                                        <?php if ($order['shape_details'] !== ''): ?>
                                            <small>Shapes: <?= h($order['shape_details']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        To pay: <?= h((string)$order['amount_to_pay']) ?><br>
                                        Paid: <?= h((string)$order['amount_paid']) ?><br>
                                        Balance: <?= h((string)$order['balance']) ?>
                                    </td>
                                    <td>
                                        Text: <?= h($order['cake_text']) ?><br>
                                        Design/Color: <?= h($order['design_color']) ?>
                                    </td>
                                    <td><?= nl2br(h($order['other_details'])) ?></td>
                                    <td>
                                        <form method="post">
                                            <input type="hidden" name="action" value="mark_received">
                                            <input type="hidden" name="order_id" value="<?= h((string)$order['id']) ?>">
                                            <button class="btn secondary" type="submit">Mark Received</button>
                                        </form>
                                        <?php if ($isAdminUser): ?>
                                            <form method="post" style="margin-top: 6px;">
                                                <input type="hidden" name="action" value="delete_order">
                                                <input type="hidden" name="order_id" value="<?= h((string)$order['id']) ?>">
                                                <button class="btn danger" type="submit">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isLoggedIn && $activeTab === 'received'): ?>
            <div class="card">
                <h2>Orders Received by Pickup Date</h2>
                <form method="get" style="margin-bottom: 12px;">
                    <input type="hidden" name="tab" value="received">
                    <label for="received_date">Select Pickup Date</label>
                    <select id="received_date" name="received_date" onchange="this.form.submit()">
                        <?php if (!$receivedDates): ?>
                            <option value="">No received dates</option>
                        <?php endif; ?>
                        <?php foreach ($receivedDates as $row): ?>
                            <option value="<?= h($row['pickup_date']) ?>" <?= $receivedSelectedDate === $row['pickup_date'] ? 'selected' : '' ?>>
                                <?= h($row['pickup_date']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if (!$receivedOrders): ?>
                    <p class="muted">No received orders for selected date.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Owner</th>
                                <th>Cake Tier</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Instructions</th>
                                <?php if ($isAdminUser): ?><th>Action</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($receivedOrders as $order): ?>
                                <tr>
                                    <td><?= h($order['owner_name']) ?></td>
                                    <td><?= h($order['cake_price_tier']) ?></td>
                                    <td><?= h((string)$order['amount_paid']) ?></td>
                                    <td><?= h((string)$order['balance']) ?></td>
                                    <td>
                                        Text: <?= h($order['cake_text']) ?><br>
                                        Design/Color: <?= h($order['design_color']) ?>
                                    </td>
                                    <?php if ($isAdminUser): ?>
                                        <td>
                                            <form method="post">
                                                <input type="hidden" name="action" value="delete_order">
                                                <input type="hidden" name="order_id" value="<?= h((string)$order['id']) ?>">
                                                <button class="btn danger" type="submit">Delete</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isLoggedIn && $isAdminUser && $activeTab === 'cashflow'): ?>
            <div class="card">
                <h2>Admin Cash Flow Dashboard</h2>
                <p class="muted">Track daily money in, expenses, net cash, and pending balances.</p>

                <form method="get" class="grid" style="margin-bottom:12px;">
                    <input type="hidden" name="tab" value="cashflow">
                    <div>
                        <label for="cashflow_start">From</label>
                        <input id="cashflow_start" name="cashflow_start" type="date" value="<?= h($cashflowStartDate) ?>">
                    </div>
                    <div>
                        <label for="cashflow_end">To</label>
                        <input id="cashflow_end" name="cashflow_end" type="date" value="<?= h($cashflowEndDate) ?>">
                    </div>
                    <div style="display:flex; align-items:flex-end;">
                        <button class="btn" type="submit">Apply Period</button>
                    </div>
                </form>

                <div class="grid" style="margin-bottom:12px;">
                    <div class="card">
                        <strong>Cash In (Received Orders)</strong>
                        <div><?= h(number_format($financeSummary['cash_in'], 2)) ?></div>
                    </div>
                    <div class="card">
                        <strong>Cash Out (Expenses)</strong>
                        <div><?= h(number_format($financeSummary['cash_out'], 2)) ?></div>
                    </div>
                    <div class="card">
                        <strong>Net Cash</strong>
                        <div><?= h(number_format($financeSummary['net'], 2)) ?></div>
                    </div>
                    <div class="card">
                        <strong>Pending Balances</strong>
                        <div><?= h(number_format($financeSummary['pending_balances'], 2)) ?></div>
                    </div>
                </div>

                <h3>Record Expense</h3>
                <form method="post" class="grid" style="margin-bottom:12px;">
                    <input type="hidden" name="action" value="add_expense">
                    <div>
                        <label for="expense_date">Expense Date</label>
                        <input id="expense_date" name="expense_date" type="date" required value="<?= h(date('Y-m-d')) ?>">
                    </div>
                    <div>
                        <label for="expense_category">Category</label>
                        <input id="expense_category" name="expense_category" type="text" placeholder="Ingredients, fuel, salary..." required>
                    </div>
                    <div>
                        <label for="expense_amount">Amount</label>
                        <input id="expense_amount" name="expense_amount" type="number" step="0.01" min="0" required>
                    </div>
                    <div>
                        <label for="expense_description">Description</label>
                        <input id="expense_description" name="expense_description" type="text" placeholder="Optional details">
                    </div>
                    <div style="display:flex; align-items:flex-end;">
                        <button class="btn" type="submit">Save Expense</button>
                    </div>
                </form>

                <h3>Daily Cash Flow</h3>
                <?php if (!$dailyCashFlowRows): ?>
                    <p class="muted">No cash movement for this period yet.</p>
                <?php else: ?>
                    <table style="margin-bottom: 12px;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Cash In</th>
                                <th>Cash Out</th>
                                <th>Net</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dailyCashFlowRows as $row): ?>
                                <tr>
                                    <td><?= h($row['day']) ?></td>
                                    <td><?= h(number_format((float)$row['cash_in'], 2)) ?></td>
                                    <td><?= h(number_format((float)$row['cash_out'], 2)) ?></td>
                                    <td><?= h(number_format((float)$row['net'], 2)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h3>Expenses List</h3>
                <?php if (!$recentExpenses): ?>
                    <p class="muted">No expenses recorded for this period.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentExpenses as $expense): ?>
                                <tr>
                                    <td><?= h($expense['expense_date']) ?></td>
                                    <td><?= h($expense['category']) ?></td>
                                    <td><?= h($expense['description']) ?></td>
                                    <td><?= h(number_format((float)$expense['amount'], 2)) ?></td>
                                    <td>
                                        <form method="post">
                                            <input type="hidden" name="action" value="delete_expense">
                                            <input type="hidden" name="expense_id" value="<?= h((string)$expense['id']) ?>">
                                            <button class="btn danger" type="submit">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isLoggedIn && $isAdminUser && $activeTab === 'users'): ?>
            <div class="card">
                <h2>User Management (Admin Only)</h2>
                <div class="grid">
                    <div>
                        <h3>Add Admin / Receptionist</h3>
                        <form method="post">
                            <input type="hidden" name="action" value="add_user">
                            <div style="margin-bottom:10px;">
                                <label for="new_username">Username</label>
                                <input id="new_username" name="new_username" type="text" required>
                            </div>
                            <div style="margin-bottom:10px;">
                                <label for="new_password">Password</label>
                                <input id="new_password" name="new_password" type="password" required>
                            </div>
                            <div style="margin-bottom:10px;">
                                <label for="new_role">Role</label>
                                <select id="new_role" name="new_role" required>
                                    <option value="receptionist">Receptionist</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            <button class="btn" type="submit">Create Account</button>
                        </form>
                    </div>
                    <div>
                        <h3>Existing Accounts</h3>
                        <?php if (!$allUsers): ?>
                            <p class="muted">No user accounts found.</p>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Created</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($allUsers as $u): ?>
                                    <tr>
                                        <td><?= h($u['username']) ?></td>
                                        <td><?= h($u['role']) ?></td>
                                        <td><?= h($u['created_at']) ?></td>
                                        <td>
                                            <?php if ((int)$u['id'] === (int)$_SESSION['user_id']): ?>
                                                <span class="muted">Current account</span>
                                            <?php else: ?>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= h((string)$u['id']) ?>">
                                                    <button class="btn danger" type="submit">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
<script>
    (function () {
        var shapeCountEl = document.getElementById('shape_count');
        var shapeTypeEl = document.getElementById('shape_type');
        var shapePreviewEl = document.getElementById('shape_preview_stack');
        var shapePriceGridEl = document.getElementById('shape_price_grid');
        var shapeTotalEl = document.getElementById('shape_total');
        var shapeDetailsInputEl = document.getElementById('shape_details');
        var amountToPayEl = document.getElementById('amount_to_pay');

        if (!shapeCountEl || !shapeTypeEl || !shapePreviewEl || !shapePriceGridEl || !shapeTotalEl || !shapeDetailsInputEl) {
            return;
        }

        function safeNumber(value) {
            var n = parseFloat(value);
            return Number.isFinite(n) ? n : 0;
        }

        function calculateShapesTotal() {
            var priceInputs = shapePriceGridEl.querySelectorAll('.js-shape-price');
            var total = 0;
            priceInputs.forEach(function (input) {
                total += safeNumber(input.value);
            });
            shapeTotalEl.textContent = 'Shapes Total: ' + total.toFixed(2);
            return total;
        }

        function syncShapeDetails() {
            var cards = shapePriceGridEl.querySelectorAll('.shape-price-card');
            var details = [];
            cards.forEach(function (card, idx) {
                var tierSize = card.querySelector('.js-tier-size');
                var price = card.querySelector('.js-shape-price');
                var tierLabel = card.getAttribute('data-tier-label') || ('Tier ' + (idx + 1));
                details.push({
                    index: idx + 1,
                    label: tierLabel,
                    size: safeNumber(tierSize ? tierSize.value : ''),
                    price: safeNumber(price ? price.value : ''),
                });
            });

            var summary = details
                .map(function (item) {
                    return item.label + ' (Size=' + item.size + ', Price=' + item.price + ')';
                })
                .join('; ');

            shapeDetailsInputEl.value = summary;
        }

        function renderShapeIllustration(size) {
            var wrap = document.createElement('div');
            wrap.className = 'shape-illustration';

            var tierDiv = document.createElement('div');
            tierDiv.className = 'shape-illustration-tier';
            tierDiv.style.width = Math.max(70, size * 4) + 'px';

            wrap.appendChild(tierDiv);
            return wrap;
        }

        function renderCombinedStackPreview() {
            var cards = shapePriceGridEl.querySelectorAll('.shape-price-card');
            shapePreviewEl.innerHTML = '';

            // Build one vertical stack from top to bottom by tier order.
            cards.forEach(function (card) {
                var sizeInput = card.querySelector('.js-tier-size');
                var sizeVal = safeNumber(sizeInput ? sizeInput.value : 0);
                shapePreviewEl.appendChild(renderShapeIllustration(sizeVal));
            });
        }

        function renderShapesBuilder() {
            var count = parseInt(shapeCountEl.value, 10);
            if (!Number.isFinite(count) || count < 1) {
                count = 1;
            }

            shapePreviewEl.innerHTML = '';
            shapePriceGridEl.innerHTML = '';

            for (var i = 1; i <= count; i += 1) {
                var defaultSize = 20 + ((i - 1) * 10);
                var tierLabel = 'Tier ' + i + ' (Top)';
                if (i === count) {
                    tierLabel = 'Tier ' + i + ' (Bottom)';
                } else if (i > 1) {
                    tierLabel = 'Tier ' + i + ' (Middle)';
                }

                var card = document.createElement('div');
                card.className = 'shape-price-card';
                card.setAttribute('data-tier-label', tierLabel);
                card.innerHTML =
                    '<strong>' + tierLabel + '</strong>' +
                    '<div style="margin-top:8px;">' +
                    '<label>Tier size</label>' +
                    '<input class="js-tier-size" type="number" min="0" step="1" value="' + defaultSize + '">' +
                    '</div>' +
                    '<div style="margin-top:8px;">' +
                    '<label>Price</label>' +
                    '<input class="js-shape-price" type="number" min="0" step="0.01" value="0">' +
                    '</div>';
                shapePriceGridEl.appendChild(card);

                (function bindCardEvents(cardEl) {
                    var sizeInput = cardEl.querySelector('.js-tier-size');
                    var priceInput = cardEl.querySelector('.js-shape-price');

                    function updatePreviewAndDetails() {
                        renderCombinedStackPreview();
                        calculateShapesTotal();
                        syncShapeDetails();
                    }

                    sizeInput.addEventListener('input', updatePreviewAndDetails);
                    priceInput.addEventListener('input', function () {
                        calculateShapesTotal();
                        syncShapeDetails();
                    });
                })(card);
            }

            var shapesTotal = calculateShapesTotal();
            syncShapeDetails();
            renderCombinedStackPreview();

            if (amountToPayEl && safeNumber(amountToPayEl.value) === 0 && shapesTotal > 0) {
                amountToPayEl.value = shapesTotal.toFixed(2);
            }
        }

        shapeCountEl.addEventListener('change', renderShapesBuilder);
        shapeTypeEl.addEventListener('change', renderShapesBuilder);

        renderShapesBuilder();
    })();
</script>
</body>
</html>
