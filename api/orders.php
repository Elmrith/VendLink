<?php
// api/orders.php - REST endpoint for orders management
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/vendor_access.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];

// GET: list orders (admin sees all, supplier sees theirs, vendor sees theirs)
if ($method === 'GET') {
    if ($userRole === 'admin') {
        $stmt = $pdo->query("SELECT o.*,
                vu.username AS vendorUsername,
                su.username AS supplierUsername,
                COALESCE(NULLIF(vu.name, ''), o.vendorName) AS vendorDisplayName,
                COALESCE(NULLIF(vu.businessName, ''), o.vendorBusinessName) AS vendorDisplayBusinessName,
                COALESCE(NULLIF(su.name, ''), o.supplierName) AS supplierDisplayName,
                COALESCE(NULLIF(su.businessName, ''), o.supplierBusinessName) AS supplierDisplayBusinessName
            FROM orders o
            LEFT JOIN users vu ON vu.id = o.vendorId
            LEFT JOIN users su ON su.id = o.supplierId
            ORDER BY o.createdAt DESC");
        $orders = $stmt->fetchAll();
    } elseif ($userRole === 'supplier') {
        $stmt = $pdo->prepare("SELECT o.*, vu.username AS vendorUsername
            FROM orders o
            LEFT JOIN users vu ON vu.id = o.vendorId
            WHERE o.supplierId = ?
            ORDER BY o.createdAt DESC");
        $stmt->execute([$userId]);
        $orders = $stmt->fetchAll();
    } elseif ($userRole === 'vendor') {
        $userId = verifyVendorOwnership();
        $stmt = $pdo->prepare("SELECT o.*, su.username AS supplierUsername
            FROM orders o
            LEFT JOIN users su ON su.id = o.supplierId
            WHERE o.vendorId = ?
            ORDER BY o.createdAt DESC");
        $stmt->execute([$userId]);
        $orders = $stmt->fetchAll();
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized.']);
        exit;
    }

    // attach items
    foreach ($orders as &$order) {
        $it = $pdo->prepare("SELECT * FROM order_items WHERE orderId = ?");
        $it->execute([$order['id']]);
        $order['items'] = $it->fetchAll();
        $history = $pdo->prepare("SELECT status, changedBy, changedAt FROM order_status_history WHERE orderId = ? ORDER BY changedAt, id");
        $history->execute([$order['id']]);
        $order['statusHistory'] = $history->fetchAll();
    }

    echo json_encode($orders);
    exit;
}

// POST: create new order (vendor)
if ($method === 'POST') {
    requireCsrfToken();
    if ($userRole !== 'vendor') {
        http_response_code(403);
        echo json_encode(['error' => 'Only vendors may place orders.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid order data.']);
        exit;
    }
    $productId = filter_var($input['productId'] ?? null, FILTER_VALIDATE_INT);
    $variantId = filter_var($input['variantId'] ?? null, FILTER_VALIDATE_INT);
    $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
    $paymentMethod = 'Cash on Delivery';
    $deliveryAddress = trim((string)($input['deliveryAddress'] ?? ''));
    $deliveryNotes = trim((string)($input['deliveryNotes'] ?? ''));

    if ($deliveryAddress === '') {
        $vendorAddressStmt = $pdo->prepare('SELECT address FROM users WHERE id = ? LIMIT 1');
        $vendorAddressStmt->execute([$userId]);
        $vendorAddress = trim((string)$vendorAddressStmt->fetchColumn());

        if ($vendorAddress === '') {
            $defaultAddressStmt = $pdo->prepare('SELECT CONCAT(addressLine1, IF(addressLine2 <> "", CONCAT(", ", addressLine2), ""), ", ", city, IF(province <> "", CONCAT(", ", province), ""), ", ", postalCode) AS address FROM delivery_addresses WHERE vendorId = ? AND isDefault = 1 ORDER BY updatedAt DESC LIMIT 1');
            $defaultAddressStmt->execute([$userId]);
            $vendorAddress = trim((string)$defaultAddressStmt->fetchColumn());
        }

        $deliveryAddress = $vendorAddress;
    }

    if (!$productId || !$quantity || $quantity < 1 || $deliveryAddress === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Please provide a valid product, quantity, and delivery address.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $productStmt = $pdo->prepare('SELECT id, name, price, stockQuantity, supplierId, imageUrl, hasVariants, unit FROM products WHERE id = ? AND status = \'approved\' FOR UPDATE');
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch();
        if (!$product) {
            throw new RuntimeException('Product not found or unavailable.');
        }
        $variant = null;
        if ((int)$product['hasVariants'] === 1) {
            if (!$variantId) {
                throw new RuntimeException('Please select a variant.');
            }
            $variantStmt = $pdo->prepare('SELECT id, name, price, stockQuantity, imageUrl FROM product_variants WHERE id = ? AND productId = ? FOR UPDATE');
            $variantStmt->execute([$variantId, $productId]);
            $variant = $variantStmt->fetch();
            if (!$variant || (int)$variant['stockQuantity'] < $quantity) {
                throw new RuntimeException('This variant is currently unavailable.');
            }
            $stockStmt = $pdo->prepare('UPDATE product_variants SET stockQuantity = stockQuantity - ? WHERE id = ? AND stockQuantity >= ?');
            $stockStmt->execute([$quantity, $variantId, $quantity]);
            if ($stockStmt->rowCount() !== 1) {
                throw new RuntimeException('This variant is currently unavailable.');
            }
        } else {
            if ((int)$product['stockQuantity'] < $quantity) {
                throw new RuntimeException('Insufficient stock available.');
            }
            $stockStmt = $pdo->prepare('UPDATE products SET stockQuantity = stockQuantity - ? WHERE id = ? AND stockQuantity >= ?');
            $stockStmt->execute([$quantity, $productId, $quantity]);
            if ($stockStmt->rowCount() !== 1) {
                throw new RuntimeException('Insufficient stock available.');
            }
        }

        $itemName = $product['name'] . ($variant ? ' - ' . $variant['name'] : '');
        $itemPrice = $variant ? $variant['price'] : $product['price'];
        $itemImage = $variant && $variant['imageUrl'] ? $variant['imageUrl'] : $product['imageUrl'];
        $subtotal = round((float)$itemPrice * $quantity, 2);
        $deliveryFee = $subtotal >= 5000 ? 0.00 : 150.00;
        $totalAmount = round($subtotal + $deliveryFee, 2);

        $vendorStmt = $pdo->prepare("SELECT id, name, businessName, address, contactNumber, role, isActive FROM users WHERE id = ? FOR UPDATE");
        $vendorStmt->execute([$userId]);
        $vendor = $vendorStmt->fetch();
        if (!$vendor || $vendor['role'] !== 'vendor' || !(int)$vendor['isActive']) {
            throw new RuntimeException('Vendor account is unavailable.');
        }

        $supplierLockStmt = $pdo->prepare("SELECT id, name, businessName, address, contactNumber, role, isActive FROM users WHERE id = ? FOR UPDATE");
        $supplierLockStmt->execute([$product['supplierId']]);
        $supplier = $supplierLockStmt->fetch();
        if (!$supplier || $supplier['role'] !== 'supplier' || !(int)$supplier['isActive']) {
            throw new RuntimeException('Supplier account is unavailable.');
        }
        $seqStmt = $pdo->prepare('SELECT COALESCE(MAX(supplierOrderNumber), 0) + 1 FROM orders WHERE supplierId = ?');
        $seqStmt->execute([$product['supplierId']]);
        $nextOrderNum = (int)$seqStmt->fetchColumn();

        $orderStmt = $pdo->prepare('INSERT INTO orders (vendorId, vendorName, vendorBusinessName, vendorAddress, vendorContactNumber, supplierId, supplierName, supplierBusinessName, supplierAddress, supplierContactNumber, status, totalAmount, paymentMethod, deliveryAddress, deliveryNotes, supplierOrderNumber, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, ?, ?, ?, NOW(), NOW())');
        $orderStmt->execute([
            $userId,
            $vendor['name'],
            $vendor['businessName'],
            $vendor['address'],
            $vendor['contactNumber'],
            $product['supplierId'],
            $supplier['name'],
            $supplier['businessName'],
            $supplier['address'],
            $supplier['contactNumber'],
            $totalAmount,
            $paymentMethod,
            $deliveryAddress,
            $deliveryNotes,
            $nextOrderNum
        ]);
        $orderId = (int)$pdo->lastInsertId();
        $historyStmt = $pdo->prepare('INSERT INTO order_status_history (orderId, status, changedBy, changedAt) VALUES (?, \'pending\', ?, NOW())');
        $historyStmt->execute([$orderId, $userId]);

        $itemStmt = $pdo->prepare('INSERT INTO order_items (orderId, productId, variantId, variantName, name, priceAtOrder, quantity, unit, imageUrl) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $itemStmt->execute([$orderId, $productId, $variant['id'] ?? null, $variant['name'] ?? null, $itemName, $itemPrice, $quantity, $product['unit'] ?: 'unit', $itemImage]);

        $newStock = $variant ? (int)$variant['stockQuantity'] - $quantity : (int)$product['stockQuantity'] - $quantity;
        $notificationStmt = $pdo->prepare('INSERT INTO notifications (userId, title, message, type, createdAt) VALUES (?, ?, ?, ?, NOW())');
        $notificationStmt->execute([
            $product['supplierId'],
            "New Order Received #{$nextOrderNum} for {$itemName}",
            "Vendor " . $vendor['name'] . " placed an order for ₱" . number_format($totalAmount, 2) . '.',
            'order_new'
        ]);
        if ((int)$product['stockQuantity'] >= 10 && $newStock < 10) {
            $notificationStmt->execute([
                $product['supplierId'],
                    "Low Stock Alert: {$itemName}",
                    "Stock for {$itemName} is low ({$newStock} units).",
                'inventory_alert'
            ]);
        }

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'orderId' => $orderId,
            'supplierOrderNumber' => $nextOrderNum,
            'totalAmount' => $totalAmount
        ]);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code($e->getMessage() === 'Insufficient stock available.' ? 409 : 400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Order placement failed.']);
    }
    exit;
}

// PUT: update order status (supplier/admin)
if ($method === 'PUT') {
    requireCsrfToken();
    $input = json_decode(file_get_contents('php://input'), true);
    $orderId = filter_var($input['orderId'] ?? null, FILTER_VALIDATE_INT);
    $newStatus = $input['status'] ?? 'pending';
    $allowedStatuses = ['confirmed', 'processing', 'ready_for_dispatch', 'shipped', 'delivered', 'arrival_confirmed', 'cancelled'];

    if (!$orderId || !in_array($newStatus, $allowedStatuses, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid order status update.']);
        exit;
    }
    if ($newStatus === 'arrival_confirmed' && $userRole !== 'vendor') {
        http_response_code(403);
        echo json_encode(['error' => 'Only the vendor may confirm product arrival.']);
        exit;
    }

    if ($userRole !== 'admin' && $userRole !== 'supplier' && $userRole !== 'vendor') {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized order update.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            throw new RuntimeException('Order not found.', 404);
        }

        // Authorization checks based on user role
        if ($userRole === 'vendor') {
            if ((string)$order['vendorId'] !== (string)$userId) {
                throw new RuntimeException('Unauthorized to modify this order.', 403);
            }
            if ($newStatus !== 'arrival_confirmed' && $newStatus !== 'cancelled') {
                throw new RuntimeException('Vendors may only confirm an arrived delivery or cancel a pending order.', 403);
            }
        } elseif ($userRole === 'supplier') {
            if ((string)$order['supplierId'] !== (string)$userId) {
                throw new RuntimeException('Unauthorized to modify this order.', 403);
            }
            if ($newStatus === 'arrival_confirmed') {
                throw new RuntimeException('Only the vendor may confirm product arrival.', 403);
            }
        }

        $transitions = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['processing', 'cancelled'],
            'processing' => ['ready_for_dispatch', 'cancelled'],
            'ready_for_dispatch' => ['shipped', 'cancelled'],
            'shipped' => ['delivered'],
            'delivered' => ['arrival_confirmed'],
            'arrival_confirmed' => [],
            'cancelled' => []
        ];
        if (!in_array($newStatus, $transitions[$order['status']] ?? [], true)) {
            throw new RuntimeException('Invalid order status transition.', 409);
        }

        if ($newStatus === 'cancelled') {
            $itemStmt = $pdo->prepare('SELECT productId, variantId, quantity FROM order_items WHERE orderId = ?');
            $itemStmt->execute([$orderId]);
            $stockReturn = $pdo->prepare('UPDATE products SET stockQuantity = stockQuantity + ? WHERE id = ?');
            $variantStockReturn = $pdo->prepare('UPDATE product_variants SET stockQuantity = stockQuantity + ? WHERE id = ?');
            foreach ($itemStmt->fetchAll() as $item) {
                if ($item['variantId']) {
                    $variantStockReturn->execute([(int)$item['quantity'], (int)$item['variantId']]);
                } else {
                    $stockReturn->execute([(int)$item['quantity'], (int)$item['productId']]);
                }
            }
        }

        $arrivalSql = $newStatus === 'arrival_confirmed' ? ', arrivalConfirmedAt = NOW()' : '';
        $updateStmt = $pdo->prepare("UPDATE orders SET status = ?, updatedAt = NOW(){$arrivalSql} WHERE id = ?");
        $updateStmt->execute([$newStatus, $orderId]);
        $historyStmt = $pdo->prepare('INSERT INTO order_status_history (orderId, status, changedBy, changedAt) VALUES (?, ?, ?, NOW())');
        $historyStmt->execute([$orderId, $newStatus, $userId]);

        $notifStmt = $pdo->prepare("INSERT INTO notifications (userId, title, message, type, createdAt) VALUES (?, ?, ?, 'order_status', NOW())");
        if ($userRole === 'vendor') {
            $notifRecipient = $order['supplierId'];
            $notifTitle = "Order #{$order['supplierOrderNumber']} " . ($newStatus === 'arrival_confirmed' ? 'Arrival Confirmed' : 'Cancelled by Vendor');
            $notifMsg = "Vendor " . ($order['vendorBusinessName'] ?: $order['vendorName']) . " marked order as: " . ucwords(str_replace('_', ' ', $newStatus));
        } else {
            $notifRecipient = $order['vendorId'];
            $notifTitle = "Order #{$order['supplierOrderNumber']} Updated";
            $notifMsg = 'Your order status is now: ' . ucwords(str_replace('_', ' ', $newStatus));
        }
        $notifStmt->execute([$notifRecipient, $notifTitle, $notifMsg]);
        $pdo->commit();
        echo json_encode(['success' => true, 'status' => $newStatus]);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code($e->getCode() >= 400 && $e->getCode() < 500 ? $e->getCode() : 400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Order status update failed.']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid method.']);