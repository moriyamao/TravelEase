<?php
/**
 * Terms of Service. Publicly accessible (no login required) since the
 * chat widget links here even for a user who isn't signed in yet, and
 * legal pages should never require an account to read.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service — TravelEase</title>
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
        <h1>Terms of Service</h1>
        <p class="updated">Last updated: <?= date('F j, Y') ?></p>

        <p>
            These Terms of Service ("Terms") govern your access to and use of TravelEase
            (the "Service"), including its trip-planning tools and AI-assisted support chat.
            By creating an account or using the Service, you agree to these Terms.
        </p>

        <h2>1. The Service</h2>
        <p>
            TravelEase is a trip-planning application that lets you create trips, build
            itineraries, and get help through an AI-powered chat assistant and, when
            needed, a human support team.
        </p>

        <h2>2. Your Account</h2>
        <p>
            You're responsible for the accuracy of the information you provide and for
            keeping your account credentials secure. You must be old enough to lawfully
            use this Service in your jurisdiction.
        </p>

        <h2>3. AI Chat Assistant</h2>
        <p>
            The chat assistant uses an automated language model to answer questions. Its
            responses may be inaccurate, incomplete, or out of date, and should not be
            relied upon as professional, legal, financial, or travel-safety advice.
            Always verify important information (visa requirements, health advisories,
            booking details, refund policies) with an official or authoritative source
            before making decisions based on it.
        </p>

        <h2>4. Acceptable Use</h2>
        <p>You agree not to use the Service to:</p>
        <ul>
            <li>Violate any applicable law or regulation;</li>
            <li>Upload malicious content or attempt to disrupt the Service;</li>
            <li>Attempt to access another user's account or data without authorization;</li>
            <li>Submit false, misleading, or harmful content through any form or chat feature.</li>
        </ul>

        <h2>5. Content You Submit</h2>
        <p>
            You retain ownership of the trip details, notes, and messages you submit.
            You grant TravelEase a limited license to store and process that content
            solely to operate and improve the Service.
        </p>

        <h2>6. Termination</h2>
        <p>
            We may suspend or terminate your access if you violate these Terms or misuse
            the Service. You may stop using the Service and request account deletion at
            any time.
        </p>

        <h2>7. Disclaimer of Warranties</h2>
        <p>
            The Service is provided "as is" without warranties of any kind, express or
            implied. We do not guarantee that the Service will be uninterrupted,
            error-free, or that AI-generated responses will be accurate.
        </p>

        <h2>8. Limitation of Liability</h2>
        <p>
            To the fullest extent permitted by law, TravelEase and its developers are not
            liable for any indirect, incidental, or consequential damages arising from
            your use of the Service, including decisions made based on AI chat responses.
        </p>

        <h2>9. Changes to These Terms</h2>
        <p>
            We may update these Terms from time to time. Continued use of the Service
            after changes take effect constitutes acceptance of the revised Terms.
        </p>

        <h2>10. Contact</h2>
        <p>
            Questions about these Terms can be directed through the in-app support chat.
        </p>

        <p><a href="/privacy.php">View our Privacy Policy &rarr;</a></p>
    </main>
</body>
</html>
