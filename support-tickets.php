<?php
require_once __DIR__ . '/auth.php';;
requireAdmin();
include("includes/logout.php");
require_once __DIR__ . '/includes/config.php';

$conn = db();

$adminMsg = '';
$adminError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_csrf'] ?? null)) {
        $adminError = 'Invalid form token. Please refresh the page and try again.';
    } else {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);

        if (isset($_POST['update_ticket'])) {
            $status = trim($_POST['status'] ?? 'open');
            if (!in_array($status, ['open', 'in_progress', 'resolved'], true)) {
                $status = 'open';
            }
            $stmt = $conn->prepare("UPDATE support_tickets SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $ticketId);
            $stmt->execute();
            log_activity('ticket_update', 'support', "Support ticket #$ticketId set to '$status'.", 'notice', 'ticket', (string)$ticketId);
            $adminMsg = 'Support ticket #' . $ticketId . ' updated.';
        }

        if (isset($_POST['delete_ticket'])) {
            $stmt = $conn->prepare("DELETE FROM support_tickets WHERE id = ?");
            $stmt->bind_param("i", $ticketId);
            $stmt->execute();
            log_activity('ticket_delete', 'support', "Support ticket #$ticketId deleted.", 'warning', 'ticket', (string)$ticketId);
            $adminMsg = 'Support ticket #' . $ticketId . ' deleted.';
        }
    }
}

$filterStatus = trim($_GET['status'] ?? '');
if (in_array($filterStatus, ['open', 'in_progress', 'resolved'], true)) {
    $tickets = $conn->prepare("SELECT * FROM support_tickets WHERE status = ? ORDER BY created_at DESC");
    $tickets->bind_param("s", $filterStatus);
    $tickets->execute();
    $tickets = $tickets->get_result();
} else {
    $filterStatus = '';
    $tickets = $conn->query("SELECT * FROM support_tickets ORDER BY created_at DESC");
}

$openCount = (int)$conn->query("SELECT COUNT(*) AS c FROM support_tickets WHERE status = 'open'")->fetch_assoc()['c'];
$inProgressCount = (int)$conn->query("SELECT COUNT(*) AS c FROM support_tickets WHERE status = 'in_progress'")->fetch_assoc()['c'];
$resolvedCount = (int)$conn->query("SELECT COUNT(*) AS c FROM support_tickets WHERE status = 'resolved'")->fetch_assoc()['c'];

$pageTitle = 'Support Tickets | ' . SYSTEM_NAME_FULL;
include("includes/site-header.php");
?>

<h1 class="page-title">Support Tickets</h1>
<p class="page-subtitle">View and manage messages submitted from the support page.</p>

<?php if ($adminMsg): ?><p class="message success" style="max-width:none;"><?= htmlspecialchars($adminMsg) ?></p><?php endif; ?>
<?php if ($adminError): ?><p class="message error" style="max-width:none;"><?= htmlspecialchars($adminError) ?></p><?php endif; ?>

<div class="admin-stats">
    <div class="stat-card">
        <p class="stat-label">Open</p>
        <p class="stat-value stat-warn"><?= $openCount ?></p>
        <p class="stat-hint">Awaiting review</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">In progress</p>
        <p class="stat-value"><?= $inProgressCount ?></p>
        <p class="stat-hint">Being worked on</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Resolved</p>
        <p class="stat-value stat-ok"><?= $resolvedCount ?></p>
        <p class="stat-hint">Completed tickets</p>
    </div>
</div>

<div class="card">
    <p style="margin:0 0 12px;">
        <strong>Filter:</strong>
        <?php if ($filterStatus === ''): ?><span class="status-badge badge-ok">All</span><?php else: ?><a href="support-tickets.php">All</a><?php endif; ?>
        <?php foreach (['open', 'in_progress', 'resolved'] as $s): ?>
            <?php if ($filterStatus === $s): ?><span class="status-badge <?= $s === 'resolved' ? 'badge-ok' : 'badge-warn' ?>"><?= ucfirst(str_replace('_', ' ', $s)) ?></span><?php else: ?><a href="support-tickets.php?status=<?= $s ?>"><?= ucfirst(str_replace('_', ' ', $s)) ?></a><?php endif; ?>
        <?php endforeach; ?>
    </p>

    <?php if ($tickets && $tickets->num_rows > 0): ?>
        <table class="admin-table">
            <thead>
                <tr><th>ID</th><th>Submitted</th><th>Staff</th><th>Subject</th><th>Message</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php while ($t = $tickets->fetch_assoc()):
                    $ticketId = (int)$t['id'];
                ?>
                <tr>
                    <td>#<?= $ticketId ?></td>
                    <td><?= htmlspecialchars($t['created_at']) ?><br><small><?= htmlspecialchars($t['staff_email']) ?></small></td>
                    <td><?= htmlspecialchars($t['staff_name']) ?></td>
                    <td><?= htmlspecialchars($t['subject']) ?></td>
                    <td style="max-width:320px;"><?= nl2br(htmlspecialchars($t['message'])) ?></td>
                    <td>
                        <form method="post" class="inline-form">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="ticket_id" value="<?= $ticketId ?>">
                            <select name="status" class="mini-input" title="Status">
                                <?php foreach (['open', 'in_progress', 'resolved'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $t['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="update_ticket" class="btn-mini btn-ok">Update</button>
                        </form>
                    </td>
                    <td>
                        <form method="post" class="inline-form" data-confirm="Delete this ticket? This action cannot be undone.">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="ticket_id" value="<?= $ticketId ?>">
                            <button type="submit" name="delete_ticket" class="btn-mini btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="cart-empty">No support tickets<?= $filterStatus !== '' ? ' with this status' : '' ?>.</p>
    <?php endif; ?>
</div>


<?php include("includes/site-footer.php"); ?>
