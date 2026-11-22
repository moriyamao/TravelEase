<?php
/**
 * FR-36: public information page (no sign-in needed). One page with the
 * terms of use, privacy notice, AI disclaimer and third-party services.
 * It describes what this academic app actually does; it is not a legal
 * contract and nothing here asks the reader to accept anything.
 */
$updated = 'November 22, 2026';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Information — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/information.css">
</head>
<body>
    <main class="info-page">
        <header class="info-header">
            <a class="info-brand" href="/public/index.php">TravelEase</a>
            <a href="/auth/login.php">Sign in</a>
        </header>

        <h1>About TravelEase: terms, privacy, AI and services</h1>
        <p class="info-updated">Last updated: <?= htmlspecialchars($updated, ENT_QUOTES, 'UTF-8') ?></p>
        <p class="info-lead">
            TravelEase is an academic project built for a university course. It is a trip planner for
            individuals and invited travel companions. It does not sell or book travel, take payments or
            move money. This page explains, in plain language, how it works and what happens to your information.
            It is not a legal contract and does not claim compliance with any particular law.
        </p>

        <nav class="info-toc" aria-label="On this page">
            <a href="#terms">Terms of use</a>
            <a href="#privacy">Privacy</a>
            <a href="#ai">AI disclaimer</a>
            <a href="#services">Third-party services</a>
            <a href="#requests">Your requests and contact</a>
        </nav>

        <section id="terms" aria-labelledby="terms-heading">
            <h2 id="terms-heading">Terms of use</h2>
            <h3>What the service is</h3>
            <p>
                You can create trips, plan a day-by-day itinerary, track a budget and shared expenses, save places on a map,
                keep booking references, set reminders and invite other TravelEase users to collaborate. Expense balances are
                informational only: TravelEase never sends, receives or holds money. Booking references are notes you enter;
                TravelEase does not make, change or verify bookings.
            </p>
            <h3>Your account</h3>
            <p>
                You sign up with an email address and password, which must be verified by email, or with Google Sign-In.
                Keep your password private. You are responsible for what is done through your account.
            </p>
            <h3>Private collaboration</h3>
            <p>
                Trips are private, not a public feed. A trip owner can invite another verified TravelEase user by their exact
                email address and give them a role: Editor (changes the itinerary, expenses, places and references), Commenter
                (votes and comments) or Viewer (read only). Everyone on a trip can see its shared details, such as the
                itinerary, comments, expenses, saved places and activity history, except booking references marked as limited
                or private, and other members' personal reminders. The owner can change roles, remove members and transfer
                ownership. Access ends as soon as someone is removed or leaves.
            </p>
            <h3>Acceptable use</h3>
            <p>
                Don't use TravelEase to harass others, store unlawful content, try to access trips or accounts that aren't
                yours, or overload or attack the service. Accounts that do may be suspended or deleted by an administrator.
            </p>
            <h3>No guarantees</h3>
            <p>
                This is a student project provided as it is. Features may change, break or be removed, and the service may be
                unavailable. Reminders and emails can be late or not arrive. Keep your own copies of anything important,
                such as tickets, booking confirmations and travel documents.
            </p>
        </section>

        <section id="privacy" aria-labelledby="privacy-heading">
            <h2 id="privacy-heading">Privacy</h2>
            <h3>What is stored</h3>
            <ul>
                <li>Your account: name, email address, a hashed password (or your Google account ID), role, email-verification status and notification settings.</li>
                <li>An optional profile picture you upload. It is stored on the application server, not in separate long-term storage, so it can be lost when the service is redeployed.</li>
                <li>Trip content you and your co-travellers enter: trip details and time zone, itinerary activities, votes and comments, budgets and expenses, saved places, booking references, reminders and invitations.</li>
                <li>An activity history for each trip, and in-app notifications. These use general wording and do not copy comment text, booking codes or reminder titles.</li>
                <li>Support conversations that are passed to staff (see the AI disclaimer).</li>
            </ul>
            <h3>Who can see it</h3>
            <ul>
                <li>Trip members see a trip's shared content according to their role, as described above.</li>
                <li>Personal reminders are visible only to the person they belong to, not even the trip owner.</li>
                <li>Support staff see the support conversations passed to them and how many trips that customer is on. They see a trip's details and itinerary only if its owner shares it from an open support request, read-only and only until the owner stops sharing, the request is resolved or the trip changes owner. They never see comments, expenses, booking references, reminders or other members' identities, and cannot change trips.</li>
                <li>Administrators can list user accounts, change roles and delete accounts.</li>
            </ul>
            <h3>Cookies</h3>
            <p>
                TravelEase uses one session cookie to keep you signed in. It ends when you close your browser and cannot be read by
                page scripts. TravelEase does not use advertising or analytics cookies. Google's sign-in script, loaded on the
                sign-in and profile pages, is covered by Google's own policies.
            </p>
            <h3>Storage and security</h3>
            <p>
                Passwords are hashed, pages are served over HTTPS in production, and every trip page checks your access.
                No independent security audit has been done. We don't publish a guaranteed retention period or a tested backup
                and restore schedule, so don't rely on TravelEase as the only place you keep something.
            </p>
            <h3>Email</h3>
            <p>
                Email is used to verify your address and, only if you turn it on in your notification settings, to tell you a
                reminder is due. Reminder emails are off by default and say only that a reminder is due, with a link to sign in.
                TravelEase does not send marketing email.
            </p>
        </section>

        <section id="ai" aria-labelledby="ai-heading">
            <h2 id="ai-heading">AI disclaimer</h2>
            <p>TravelEase uses Groq, a third-party AI service, for three features. AI answers can be wrong, out of date or incomplete.</p>
            <h3>Support assistant</h3>
            <p>
                When you send a message to the support chat, that message is sent to Groq to draft an answer. Earlier messages are
                not sent with it. Some topics, and requests the assistant can't answer, are passed to human support staff. When that
                happens, your message is saved so staff can read and reply to it. Don't put passwords, payment details or other
                sensitive information in the chat.
            </p>
            <h3>Trip ideas</h3>
            <p>
                When you ask for activity ideas, only the trip's destination, dates, currency, budget, and the interests and pace you
                choose are sent to Groq. Names, emails, notes, comments, expenses, booking references and reminders are not sent.
                Ideas are drafts: nothing is added to your trip until someone with permission reviews it and chooses to add it.
                Check opening hours, prices, safety and travel requirements yourself before relying on any suggestion.
            </p>
            <h3>Plan review</h3>
            <p>
                When an owner or editor presses "Review my plan", Groq receives the trip's destination, dates, time zone, currency,
                budget and cost totals, and for each activity its title, date, start and end time, category, saved place name and
                estimated cost, plus the app's own schedule warnings. Notes, comments, votes, expenses, booking references,
                reminders and member names or emails are not sent. Groq returns budget tips, ideas and suggested time changes.
                A time change is only made when someone with permission presses Apply on it. It is refused if the trip or any of its
                activities changed after the review, and applying one change ends that review. Suggestions are not saved; they disappear after 30 minutes. Each account can request up
                to five reviews an hour.
            </p>
        </section>

        <section id="services" aria-labelledby="services-heading">
            <h2 id="services-heading">Third-party services</h2>
            <dl class="info-services">
                <dt>Render</dt>
                <dd>Hosts the TravelEase web application, in the United States.</dd>
                <dt>Aiven</dt>
                <dd>Hosts the MySQL database that stores the information described above, in the United States.</dd>
                <dt>Groq</dt>
                <dd>AI service for the support assistant, trip ideas and plan review (see the AI disclaimer).</dd>
                <dt>Brevo</dt>
                <dd>Sends verification emails and, if you opt in, reminder emails. It receives your name, email address and the email's content.</dd>
                <dt>Google Sign-In</dt>
                <dd>Optional sign-in. Google confirms your account and shares your name, email address and Google account ID with TravelEase.</dd>
                <dt>Geoapify</dt>
                <dd>Place search, nearby search and travel-time estimates. It receives only the search text you type, a saved place's coordinates for a nearby search, or two saved places' coordinates when a member checks travel times, from our server. Trip notes, activity titles and member details are not sent.</dd>
                <dt>OpenStreetMap and Leaflet</dt>
                <dd>The Places &amp; map page loads the Leaflet map library from unpkg.com and map images from OpenStreetMap's tile servers. Your browser connects to them directly, so they receive your IP address and the map area you view. Directions links open OpenStreetMap in a new tab.</dd>
                <dt>GitHub Actions</dt>
                <dd>A scheduled job calls TravelEase about every five minutes to process due reminders. The call carries only a secret job key, not personal information.</dd>
            </dl>
            <p>Each service handles information under its own terms and privacy policy.</p>
        </section>

        <section id="requests" aria-labelledby="requests-heading">
            <h2 id="requests-heading">Your requests and contact</h2>
            <p>
                You can edit your profile, notification settings and trip content yourself, and leave trips you don't own.
                On your Profile page you can download a copy of your data as a JSON file, and customers can delete their own
                account after confirming their password (or a recent Google sign-in). Deleting is permanent: trips you own alone
                are deleted, you are removed from other trips, and your reminders, notifications, invitations and support requests
                are deleted. Comments and expense entries keep your name so other travelers' records stay complete. If you own a
                trip that other people are on, transfer it or delete it first. Staff and administrator accounts are removed by an
                administrator.
            </p>
            <p>
                Signed-in users can reach staff through the support chat. If you can't sign in, contact the TravelEase project team
                through the channel where you were given access to this project.
            </p>
            <p>
                This page may change as the project changes. The date at the top shows when it was last updated.
            </p>
        </section>
    </main>
</body>
</html>
