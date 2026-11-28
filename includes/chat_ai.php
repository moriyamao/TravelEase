<?php
/**
 * AI chat support helper.
 *
 * Three-way outcome per message, per the design agreed with Mori:
 *   1. Hard rules — certain topics ALWAYS escalate to a human,
 *      regardless of what the AI itself thinks it can handle
 *      (refunds, complaints, account/security issues).
 *   2. Off-topic — questions unrelated to TravelEase are declined
 *      directly, no human involved (not a real support request).
 *   3. AI self-assessment — for genuine TravelEase questions it can't
 *      confidently answer (account-specific, data-specific, anything
 *      it'd have to guess at), it escalates to a human instead.
 *
 * The Groq API key lives in .env (GROQ_API_KEY) and is never sent to
 * the browser — this file only ever runs server-side.
 */

const CHAT_HARD_ESCALATE_KEYWORDS = [
    'refund', 'chargeback', 'dispute',
    'complaint', 'complain',
    'cancel my account', 'delete my account',
    'lawsuit', 'legal', 'sue',
    'password', 'hacked', 'hack', 'security breach',
    'payment', 'charge', 'billing',
    'scam', 'fraud',
];

/**
 * Ask the AI to respond to a customer message.
 * Returns ['reply' => string, 'escalate' => bool].
 */
function ask_ai(string $message, ?array $tripSnapshot = null): array
{
    $lower = mb_strtolower($message);

    foreach (CHAT_HARD_ESCALATE_KEYWORDS as $keyword) {
        if (str_contains($lower, $keyword)) {
            return [
                'reply'    => "That's something our support team should handle directly — I'll pass this along to them.",
                'escalate' => true,
            ];
        }
    }

    // Saved planning facts are answered locally; no trip snapshot goes to Groq.
    $howTo = preg_match('/\b(how|can i|could i|change|edit|update|add|mark|set|delete|remove|archive|restore)\b/i', $message);
    if ($tripSnapshot !== null && !$howTo && preg_match('/\b(status|dates?|time\s?zone|archived?|completed?|done|progress)\b|\bwhen\b.*\btrip\b|\btrip\b.*\bwhen\b/i', $message)) {
        $status = ucfirst((string) $tripSnapshot['status']);
        $reply = 'Saved trip status: ' . $status
            . '. This is a planning status in TravelEase, not a confirmed booking or live location. '
            . 'Saved dates: ' . $tripSnapshot['start_date'] . ' to ' . $tripSnapshot['end_date']
            . ' (' . $tripSnapshot['time_zone'] . '). '
            . (int) $tripSnapshot['activity_done'] . ' of ' . (int) $tripSnapshot['activity_total']
            . ' activities marked completed. Archived: ' . ((int) $tripSnapshot['archived'] ? 'yes' : 'no') . '.';
        return ['reply' => $reply, 'escalate' => false];
    }

    $apiKey = getenv('GROQ_API_KEY');
    if (!$apiKey) {
        error_log('TravelEase: GROQ_API_KEY is not set.');
        return [
            'reply'    => "I'm having trouble reaching our AI assistant right now — I'll connect you with our support team instead.",
            'escalate' => true,
        ];
    }

    $systemPrompt = <<<PROMPT
You are the TravelEase support assistant. TravelEase is a simple,
personal web-based travel planner: users create trips, add
destinations, build a day-by-day itinerary, and track an estimated
vs. actual budget for transportation, hotel, food, and activities.

Never use markdown formatting (no **, no numbered lists, no headers)
-- reply in plain sentences with line breaks only, since your output
is displayed as plain text.

Do not describe specific buttons, icons, or page layouts you were not
explicitly told about -- you do not actually know what the TravelEase
interface looks like. Speak only in general terms about what a user
can do (e.g. "you can add a destination from the trip page"), not how
it's laid out visually. If asked for exact steps/button names, say
you're not certain of the exact interface and offer to connect them
with support instead.

If the question is NOT about TravelEase at all (general knowledge,
coding help, anything unrelated to trip planning), start your reply
with exactly the token [OFF_TOPIC] followed by a brief, friendly
sentence saying you can only help with TravelEase questions. Do not
escalate this to a human -- it's not a real support request.

If the question IS about TravelEase, but is about their specific
account, their data, billing, or anything you cannot confidently and
fully answer without guessing, start your reply with exactly the
token [ESCALATE] followed by a short, friendly sentence telling them
you're connecting them with the support team.
PROMPT;

    $payload = json_encode([
        'model' => 'openai/gpt-oss-20b',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $message],
        ],
        'max_tokens'  => 400,
        'temperature' => 0.3,
    ]);

    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $override = getenv('GROQ_API_URL') ?: '';
    if ($override !== '') {
        $parts = parse_url($override);
        if (!is_array($parts) || !in_array(PHP_SAPI, ['cli', 'cli-server'], true)
            || ($parts['scheme'] ?? '') !== 'http'
            || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            return ['reply' => 'The assistant is unavailable right now. Please try again later.', 'escalate' => false];
        }
        $url = rtrim($override, '/') . '/openai/v1/chat/completions';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log("TravelEase: Groq API call failed (HTTP $httpCode): $curlError");
        return [
            'reply'    => "I'm having trouble reaching our AI assistant right now — I'll connect you with our support team instead.",
            'escalate' => true,
        ];
    }

    $data  = json_decode($response, true);
    $reply = trim($data['choices'][0]['message']['content'] ?? '');

    if ($reply === '') {
        return [
            'reply'    => "I wasn't able to put together an answer — let me connect you with our support team.",
            'escalate' => true,
        ];
    }

    if (str_starts_with($reply, '[OFF_TOPIC]')) {
        return [
            'reply'    => trim(substr($reply, strlen('[OFF_TOPIC]'))),
            'escalate' => false,
        ];
    }

    if (str_starts_with($reply, '[ESCALATE]')) {
        return [
            'reply'    => trim(substr($reply, strlen('[ESCALATE]'))),
            'escalate' => true,
        ];
    }

    return [
        'reply'    => $reply,
        'escalate' => false,
    ];
}
