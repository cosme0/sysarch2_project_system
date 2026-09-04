<?php
include("auth.php");
requireLogin();
include("includes/logout.php");
require_once __DIR__ . '/includes/config.php';

$contactMsg = '';
$contactError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_contact'])) {
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '' || $email === '' || $message === '') {
        $contactError = 'Please fill in your name, email, and a message before submitting.';
    } else {
        $ticketConn = mysqli_connect("localhost", "root", "", "db_users");
        if (!$ticketConn) {
            $contactError = 'Could not record the ticket. Please try again later.';
        } else {
            $stmt = $ticketConn->prepare(
                "INSERT INTO support_tickets (staff_name, staff_email, subject, message) VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param("ssss", $name, $email, $subject, $message);
            $stmt->execute();
            $stmt->close();
            mysqli_close($ticketConn);
            $contactMsg = 'Your support ticket has been recorded. The system administrator will follow up shortly.';
        }
    }
}

$pageTitle = 'Support | ' . SYSTEM_NAME_FULL;
include("includes/site-header.php");
?>

<h1 class="page-title">Technical support</h1>
<p class="page-subtitle">Report dispenser issues, inventory discrepancies, or account problems.</p>

<div class="card card-narrow">
    <?php if ($contactMsg !== ''): ?>
        <p class="message success" style="max-width:none;margin-bottom:20px;"><?= htmlspecialchars($contactMsg) ?></p>
    <?php endif; ?>
    <?php if ($contactError !== ''): ?>
        <p class="message error" style="max-width:none;margin-bottom:20px;"><?= htmlspecialchars($contactError) ?></p>
    <?php endif; ?>

    <form method="post" action="contact.php">
        <div class="form-group">
            <label for="name">Staff name</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars(trim(($_SESSION['fname'] ?? '') . ' ' . ($_SESSION['lname'] ?? ''))) ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($_SESSION['email'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="subject">Issue type</label>
            <select id="subject" name="subject">
                <option>Automated dispenser malfunction</option>
                <option>Inventory / stock mismatch</option>
                <option>Login or account access</option>
                <option>System feature request</option>
                <option>Other</option>
            </select>
        </div>
        <div class="form-group">
            <label for="message">Details</label>
            <textarea id="message" name="message" required placeholder="Describe the issue, SKU, or workstation ID…"></textarea>
        </div>
        <button type="submit" name="send_contact" class="btn-primary">Submit ticket</button>
    </form>

    <p style="margin-top:24px;text-align:center;font-size:0.9rem;color:var(--text-muted);">
        <strong>IT helpdesk:</strong> support@amdpms.local<br>
        <strong>On-site pharmacy:</strong> Building A, Room 204 &nbsp;|&nbsp; Ext. 4402
    </p>
</div>

<?php include("includes/site-footer.php"); ?>
