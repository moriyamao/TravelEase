<?php
/**
 * Privacy Policy. Publicly accessible (no login required), same
 * reasoning as terms.php.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <style>
        .legal-container {
            max-width: 720px;
            margin: 3rem auto;
            padding: 2.5rem 2.75rem;
            background: var(--paper-white);
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: var(--shadow);
        }
        .legal-container h1 { margin-top: 0; }
        .legal-container h2 { font-size: 1.05rem; margin: 1.75rem 0 0.5rem; }
        .legal-container p, .legal-container li { color: var(--ink-muted); font-size: 0.92rem; line-height: 1.65; }
        .legal-container .updated { color: var(--ink-muted); font-size: 0.8rem; margin-bottom: 2rem; }
        .legal-container a { color: var(--stamp-dark); }
        .legal-back { display: inline-block; margin-bottom: 1.5rem; font-size: 0.85rem; }
    </style>
</head>
<body>
    <main class="legal-container">
        <a class="legal-back" href="javascript:history.back()">&larr; Back</a>
        <h1>Privacy Policy</h1>
        <p class="updated">Last updated: <?= date('F j, Y') ?></p>

        <p>
            This Privacy Policy explains what information TravelEase collects, how it's
            used, and the choices you have. By using the Service, you agree to the
            practices described here.
        </p>

        <h2>1. Information We Collect</h2>
        <ul>
            <li><strong>Account information:</strong> name, email address, and, if you sign in with Google, your Google account identifier.</li>
            <li><strong>Trip data:</strong> trips, itinerary items, destinations, dates, notes, and budgets you enter.</li>
            <li><strong>Support &amp; chat content:</strong> messages you send to the AI assistant or to human support staff.</li>
            <li><strong>Profile picture:</strong> if you choose to upload one.</li>
            <li><strong>Basic technical data:</strong> such as timestamps of account activity, used for security and troubleshooting.</li>
        </ul>

        <h2>2. How We Use Information</h2>
        <ul>
            <li>To operate core features: trip planning, itineraries, and account access;</li>
            <li>To respond to support requests, via the AI assistant or human staff;</li>
            <li>To maintain the security of your account and the Service;</li>
            <li>To improve the Service based on how it's used.</li>
        </ul>

        <h2>3. AI Chat &amp; Third-Party Processing</h2>
        <p>
            Messages you send to the AI chat assistant are sent to a third-party language
            model provider in order to generate a response. We do not control that
            provider's internal retention practices beyond what's needed to process your
            request. Avoid sharing sensitive personal information (passwords, payment
            details, government ID numbers) in chat messages.
        </p>

        <h2>4. How We Store Information</h2>
        <p>
            Your data is stored in a managed database with access restricted to what the
            application needs to function. Passwords are never stored in plain text —
            they are hashed using industry-standard one-way hashing before being saved.
        </p>

        <h2>5. Sharing of Information</h2>
        <p>
            We do not sell your personal information. We share information only:
        </p>
        <ul>
            <li>With the AI chat provider, solely to generate chat responses;</li>
            <li>With our hosting and database providers, solely to operate the Service;</li>
            <li>When required by law or to protect the rights and safety of users.</li>
        </ul>

        <h2>6. Your Choices</h2>
        <ul>
            <li>You can update or delete your profile information at any time from your account.</li>
            <li>You can request deletion of your account and associated data through support.</li>
            <li>You can stop using the AI chat feature at any time; it is optional.</li>
        </ul>

        <h2>7. Data Retention</h2>
        <p>
            We retain account and trip data for as long as your account is active, or as
            needed to provide the Service. You may request deletion at any time.
        </p>

        <h2>8. Children's Privacy</h2>
        <p>
            The Service is not directed at children and is not intended for use by anyone
            below the minimum age required to enter into these terms in their jurisdiction.
        </p>

        <h2>9. Changes to This Policy</h2>
        <p>
            We may update this Privacy Policy from time to time. Continued use of the
            Service after changes take effect constitutes acceptance of the revised policy.
        </p>

        <h2>10. Contact</h2>
        <p>
            Questions about this Privacy Policy can be directed through the in-app support chat.
        </p>

        <p><a href="/terms.php">View our Terms of Service &rarr;</a></p>
    </main>
</body>
</html>
