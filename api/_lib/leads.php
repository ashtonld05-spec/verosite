<?php
// VERO Lead Finder: finds local businesses that need a website, checks their sites, and scores them.
// Used only by the signed-in admin dashboard (admin/api.php). Not web-accessible (see .htaccess).
declare(strict_types=1);

const LEAD_STATUSES = ['New', 'Contacted', 'Interested', 'Not Interested', 'Client'];
const LEAD_UA_TOKEN = 'VeroLeadFinder';
const LEAD_UA = 'VeroLeadFinder/1.0 (+https://veroagency.co; website quality check)';
const LEAD_KEY_NAMES = ['google_places' => 'Google Places API', 'pagespeed' => 'Google PageSpeed', 'brave' => 'Brave Search', 'firecrawl' => 'Firecrawl', 'anthropic' => 'Claude (Anthropic)'];

const LEAD_CATEGORIES = [
    'HVAC' => ['HVAC contractor', 'air conditioning repair', 'heating and cooling company'],
    'Plumbers' => ['plumber', 'plumbing company', 'drain cleaning service'],
    'Electricians' => ['electrician', 'electrical contractor'],
    'Roofing' => ['roofing contractor', 'roof repair'],
    'Landscaping' => ['landscaping company', 'lawn care service', 'landscaper'],
    'Pressure Washing' => ['pressure washing service', 'power washing'],
    'Auto Detailing' => ['auto detailing', 'mobile car detailing'],
    'Cleaning' => ['house cleaning service', 'commercial cleaning company', 'maid service'],
    'Restaurants' => ['restaurant', 'family restaurant', 'taqueria'],
    'Construction' => ['construction company', 'general contractor', 'remodeling contractor'],
    'Painters' => ['house painter', 'painting contractor'],
    'Pest Control' => ['pest control service', 'exterminator'],
    'Handyman' => ['handyman service'],
    'Pool Service' => ['pool cleaning service', 'pool repair'],
    'Garage Doors' => ['garage door repair'],
    'Tree Service' => ['tree service', 'tree trimming'],
    'Flooring' => ['flooring contractor', 'flooring installation'],
    'Concrete' => ['concrete contractor'],
    'Moving' => ['moving company', 'movers'],
    'Locksmith' => ['locksmith'],
    'Tattoo Shops' => ['tattoo shop', 'tattoo artist'],
];

const LEAD_WEBSITE_LABELS = [
    'none_verified' => 'No website (verified)', 'none_google_only' => 'No website on Google', 'social_only' => 'Social media only',
    'found_unlisted' => 'Has website (not on Google)', 'unreachable' => 'Website broken / down', 'placeholder' => 'Placeholder / parked site',
    'blocked_robots' => 'Website (not analyzed)', 'has_website' => 'Has website',
];

const LEAD_DIRECTORY_HOSTS = ['yelp.com', 'yellowpages.com', 'bbb.org', 'angi.com', 'angieslist.com', 'homeadvisor.com', 'thumbtack.com',
    'mapquest.com', 'nextdoor.com', 'manta.com', 'chamberofcommerce.com', 'google.com', 'g.page', 'business.site', 'apple.com', 'bing.com',
    'linkedin.com', 'tiktok.com', 'youtube.com', 'twitter.com', 'x.com', 'porch.com', 'houzz.com', 'birdeye.com', 'buildzoom.com',
    'superpages.com', 'yahoo.com', 'alignable.com', 'dnb.com', 'opencorporates.com', 'bizapedia.com', 'nvsos.gov', 'zoominfo.com',
    'facebook.com', 'fb.com', 'instagram.com', 'pinterest.com', 'reddit.com', 'tripadvisor.com', 'doordash.com', 'ubereats.com', 'grubhub.com',
    'opentable.com', 'restaurantguru.com', 'foursquare.com', 'cylex.us.com', 'hotfrog.com', 'merchantcircle.com', 'brownbook.net', 'local.com',
    'citysearch.com', 'networx.com', 'fixr.com', 'expertise.com', 'threebestrated.com', 'loc8nearme.com', 'showmelocal.com', 'yellowbook.com',
    'ezlocal.com', 'n49.com', 'indeed.com', 'glassdoor.com', 'wikipedia.org', 'carfax.com', 'dexknows.com', 'linktr.ee', 'wsimg.com',
    'square.site', 'nicelocal.com', 'storeboard.com', 'us-business.info', 'goo.gl'];
const LEAD_SOCIAL_HOSTS = ['facebook.com', 'fb.com', 'instagram.com', 'linktr.ee', 'yelp.com', 'business.site', 'g.page', 'nextdoor.com',
    'tiktok.com', 'x.com', 'twitter.com', 'youtube.com', 'linkedin.com', 'google.com', 'goo.gl', 'linkin.bio', 'beacons.ai'];

class LeadBlocked extends RuntimeException {}
class LeadApiError extends RuntimeException {}

// ---------------------------------------------------------------- config & keys
function lead_cfg(string $k, $default = null) {
    $c = vero_config() ?? [];
    return $c['leads'][$k] ?? $default;
}
function lead_endpoint(string $name, string $default): string {
    return rtrim((string)(lead_cfg('endpoints', [])[$name] ?? $default), '/');
}
function lead_crypto_key(): string {
    return hash('sha256', 'vero-leads-keys|' . (vero_config()['secret'] ?? ''), true);
}
function lead_key(string $name): string {
    $fixed = lead_cfg('keys', [])[$name] ?? '';
    if ($fixed !== '') return (string)$fixed;
    $blob = setting('lead_key_' . $name, '');
    if ($blob === '' || !function_exists('openssl_decrypt')) return '';
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', lead_crypto_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $pt === false ? '' : $pt;
}
function lead_set_key(string $name, string $value): void {
    if (!isset(LEAD_KEY_NAMES[$name])) return;
    if ($value === '') { set_setting('lead_key_' . $name, ''); return; }
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($value, 'aes-256-gcm', lead_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    set_setting('lead_key_' . $name, base64_encode($iv . $tag . $ct));
}
function lead_keys_status(): array {
    $out = [];
    foreach (LEAD_KEY_NAMES as $k => $label) {
        $v = lead_key($k);
        $out[$k] = ['label' => $label, 'set' => $v !== '', 'hint' => $v !== '' ? '••••' . substr($v, -4) : ''];
    }
    $out['pagespeed']['uses_google'] = lead_key('pagespeed') === '' && lead_key('google_places') !== '';
    return $out;
}
function lead_search_provider(): ?string {
    if (lead_key('brave') !== '') return 'brave';
    if (lead_key('firecrawl') !== '') return 'firecrawl';
    return null;
}
function lead_services(): array {
    return ['google_places' => lead_key('google_places') !== '', 'pagespeed' => (lead_key('pagespeed') ?: lead_key('google_places')) !== '',
        'web_search' => lead_search_provider(), 'ai' => lead_key('anthropic') !== ''];
}
function lead_sign(array $place): string { return hash_hmac('sha256', 'place:' . json_encode($place, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), vero_config()['secret']); }

// ---------------------------------------------------------------- small helpers
function lead_norm_phone(?string $p): ?string {
    if (!$p) return null;
    $d = preg_replace('/\D/', '', $p) ?? '';
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return strlen($d) >= 10 ? $d : null;
}
function lead_host(?string $url): string { return strtolower((string)parse_url((string)$url, PHP_URL_HOST)); }
function lead_reg_domain(?string $urlOrHost): ?string {
    if (!$urlOrHost) return null;
    $h = str_contains($urlOrHost, '//') ? lead_host($urlOrHost) : strtolower($urlOrHost);
    $h = preg_replace('/^www\./', '', rtrim($h, '.'));
    if ($h === '') return null;
    $p = explode('.', $h); $n = count($p);
    if ($n > 2 && in_array($p[$n - 2], ['co', 'com', 'net', 'org'], true) && strlen($p[$n - 1]) === 2) return implode('.', array_slice($p, -3));
    return $n >= 2 ? implode('.', array_slice($p, -2)) : $h;
}
function lead_host_in(string $url, array $set): bool {
    $d = lead_reg_domain($url); $h = preg_replace('/^www\./', '', lead_host($url));
    return in_array($d, $set, true) || in_array($h, $set, true);
}
function lead_name_tokens(?string $name): array {
    preg_match_all('/[a-z0-9]+/', strtolower((string)$name), $m);
    $stop = ['llc', 'inc', 'co', 'the', 'and', 'of', 'company', 'corp', 'services', 'service', 'ltd', 'pllc', 'group'];
    return array_values(array_filter($m[0], fn($w) => strlen($w) > 1 && !in_array($w, $stop, true)));
}
function lead_name_ratio(?string $name, ?string $text): float {
    $t = lead_name_tokens($name);
    if (!$t) return 0.0;
    $hay = preg_replace('/[^a-z0-9]/', '', strtolower((string)$text));
    return count(array_filter($t, fn($x) => str_contains($hay, $x))) / count($t);
}
function lead_queries(string $location, string $category): array {
    $locs = array_values(array_filter(array_map('trim', preg_split('/[;|]/', $location) ?: [])));
    $terms = LEAD_CATEGORIES[$category] ?? [$category];
    $q = [];
    foreach ($locs as $l) foreach ($terms as $t) $q[] = "$t in $l";
    return $q;
}

// ---------------------------------------------------------------- JSON APIs (Google, Brave, Firecrawl, Claude)
function lead_api(string $method, string $url, array $headers, $body = null, int $timeout = 30): array {
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) $h[] = "$k: $v";
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) throw new LeadApiError('Network error: ' . $err);
    $j = json_decode((string)$raw, true);
    return [$code, is_array($j) ? $j : null];
}

// ---------------------------------------------------------------- Google Places (official API, no Maps scraping)
function lead_places_search(string $query, ?string $pageToken = null): array {
    $key = lead_key('google_places');
    if ($key === '') throw new LeadApiError('Add your Google Places API key in Settings → Lead Finder API keys first.');
    $mask = 'places.id,places.displayName,places.formattedAddress,places.addressComponents,places.nationalPhoneNumber,places.internationalPhoneNumber,places.websiteUri,places.googleMapsUri,places.rating,places.userRatingCount,places.businessStatus,places.primaryTypeDisplayName,nextPageToken';
    $body = ['textQuery' => $query, 'pageSize' => 20, 'regionCode' => 'US', 'languageCode' => 'en'];
    if ($pageToken) $body['pageToken'] = $pageToken;
    for ($i = 0; $i < 3; $i++) {
        [$code, $j] = lead_api('POST', lead_endpoint('places', 'https://places.googleapis.com') . '/v1/places:searchText',
            ['Content-Type' => 'application/json', 'X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => $mask], $body, 30);
        if ($code === 429) { sleep(2 * ($i + 1)); continue; }
        if ($code >= 400 || $j === null) throw new LeadApiError('Google Places error ' . $code . ': ' . ($j['error']['message'] ?? 'request failed'));
        return ['places' => array_map('lead_parse_place', $j['places'] ?? []), 'next' => $j['nextPageToken'] ?? null];
    }
    throw new LeadApiError('Google Places is rate limiting requests. Try again in a minute.');
}
function lead_parse_place(array $p): array {
    $find = function (string $t) use ($p) { foreach ($p['addressComponents'] ?? [] as $c) if (in_array($t, $c['types'] ?? [], true)) return $c; return null; };
    $city = $find('locality') ?? $find('sublocality') ?? $find('postal_town');
    return [
        'place_id' => (string)($p['id'] ?? ''), 'name' => (string)($p['displayName']['text'] ?? ''), 'address' => $p['formattedAddress'] ?? null,
        'city' => $city['longText'] ?? null, 'state' => $find('administrative_area_level_1')['shortText'] ?? null,
        'phone' => $p['nationalPhoneNumber'] ?? ($p['internationalPhoneNumber'] ?? null), 'website' => $p['websiteUri'] ?? null,
        'google_maps_url' => $p['googleMapsUri'] ?? null, 'rating' => isset($p['rating']) ? (float)$p['rating'] : null,
        'review_count' => isset($p['userRatingCount']) ? (int)$p['userRatingCount'] : null, 'business_status' => $p['businessStatus'] ?? null,
        'google_type' => $p['primaryTypeDisplayName']['text'] ?? null,
    ];
}

// ---------------------------------------------------------------- polite, safe website fetching
function lead_check_url(string $url): array {
    $p = parse_url($url);
    $scheme = strtolower($p['scheme'] ?? '');
    $host = strtolower(trim($p['host'] ?? '', '[]'));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') throw new LeadBlocked('Only http/https website addresses are checked');
    $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (lead_cfg('allow_private', false)) return [$host, $port, null];
    if (!in_array($port, [80, 443], true)) throw new LeadBlocked('Unusual port');
    if ($host === 'localhost' || !str_contains($host, '.') || str_contains($host, ':')) throw new LeadBlocked('Private address');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) throw new LeadBlocked('Website address does not exist (DNS lookup failed)');
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new LeadBlocked('Website points to a private network address');
    }
    return [$host, $port, $ips[0]];
}

final class LeadFetcher {
    private array $last = [];
    private array $robots = [];
    public int $delayMs;
    public function __construct() { $this->delayMs = (int)lead_cfg('delay_ms', 700); }

    private function wait(string $host, int $delay): void {
        $w = (($this->last[$host] ?? 0) + $delay / 1000) - microtime(true);
        if ($w > 0) usleep((int)($w * 1e6));
        $this->last[$host] = microtime(true);
    }

    public function fetch(string $url, string $method = 'GET', int $maxBytes = 2500000, ?int $delay = null, int $timeout = 12): array {
        $start = microtime(true); $hops = [];
        for ($i = 0; $i < 6; $i++) {
            [$host, $port, $ip] = lead_check_url($url);
            $this->wait($host, $delay ?? $this->delayMs);
            $body = ''; $headers = [];
            $ch = curl_init($url);
            $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => LEAD_UA, CURLOPT_ENCODING => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language: en-US,en;q=0.8'],
                CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) { $p = strpos($line, ':'); if ($p) $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1)); return strlen($line); },
                CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, $maxBytes) { $body .= $chunk; return strlen($body) > $maxBytes ? 0 : strlen($chunk); }];
            if ($ip) $opts[CURLOPT_RESOLVE] = ["$host:$port:$ip"]; // pin the checked IP (stops DNS-rebinding tricks)
            curl_setopt_array($ch, $opts);
            $ok = curl_exec($ch);
            $errno = curl_errno($ch); $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($ok === false && !($errno === CURLE_WRITE_ERROR && strlen($body) > $maxBytes)) {
                if (in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true)) throw new LeadApiError('ssl: ' . $err);
                if ($errno === CURLE_OPERATION_TIMEDOUT) throw new LeadApiError('timeout');
                throw new LeadApiError($err ?: 'connection failed');
            }
            if (in_array($code, [301, 302, 303, 307, 308], true) && !empty($headers['location'])) {
                $hops[] = $url;
                $url = lead_resolve_url($headers['location'], $url);
                if ($code === 303) $method = 'GET';
                continue;
            }
            return ['status' => $code, 'url' => $url, 'text' => substr($body, 0, $maxBytes), 'bytes' => strlen($body),
                'elapsed' => round(microtime(true) - $start, 2), 'headers' => $headers, 'redirects' => $hops];
        }
        throw new LeadApiError('Too many redirects');
    }

    public function robotsAllows(string $url): bool {
        $p = parse_url($url);
        $base = strtolower($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (!array_key_exists($base, $this->robots)) {
            $rules = [];
            try { $r = $this->fetch($base . '/robots.txt', 'GET', 300000, 0, 8); if ($r['status'] < 400) $rules = lead_parse_robots($r['text']); } catch (Throwable $e) {}
            $this->robots[$base] = $rules;
        }
        return lead_robots_allowed($this->robots[$base], ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : ''));
    }
}

function lead_resolve_url(string $href, string $base): ?string {
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
    if ($href === '' || preg_match('/^(javascript|data|mailto|tel):/i', $href)) return null;
    if (preg_match('#^https?://#i', $href)) return $href;
    $b = parse_url($base);
    if (!$b || empty($b['host'])) return null;
    $root = ($b['scheme'] ?? 'http') . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($href, '//')) return ($b['scheme'] ?? 'http') . ':' . $href;
    if ($href[0] === '/') return $root . $href;
    if ($href[0] === '#' || $href[0] === '?') return $root . ($b['path'] ?? '/') . $href;
    $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    return $root . $dir . $href;
}

function lead_parse_robots(string $text): array {
    $groups = []; $cur = null; $lastAgent = false;
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $line = trim(preg_replace('/#.*/', '', $line));
        if (!preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m)) continue;
        $k = strtolower($m[1]); $v = trim($m[2]);
        if ($k === 'user-agent') {
            if (!$lastAgent || $cur === null) { $groups[] = ['agents' => [], 'rules' => []]; $cur = count($groups) - 1; }
            $groups[$cur]['agents'][] = strtolower($v); $lastAgent = true;
        } else {
            $lastAgent = false;
            if ($cur !== null && ($k === 'allow' || $k === 'disallow')) $groups[$cur]['rules'][] = ['allow' => $k === 'allow', 'path' => $v];
        }
    }
    $ours = array_filter($groups, fn($g) => (bool)array_filter($g['agents'], fn($a) => $a !== '*' && str_contains(strtolower(LEAD_UA_TOKEN), $a)));
    $chosen = $ours ?: array_filter($groups, fn($g) => in_array('*', $g['agents'], true));
    $rules = [];
    foreach ($chosen as $g) $rules = array_merge($rules, $g['rules']);
    return $rules;
}
function lead_robots_allowed(array $rules, string $path): bool {
    $best = null;
    foreach ($rules as $r) {
        if ($r['path'] === '') continue;
        $re = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($r['path'], '#')) . '#';
        if (@preg_match($re, $path) && (!$best || strlen($r['path']) > strlen($best['path']) || (strlen($r['path']) === strlen($best['path']) && $r['allow']))) $best = $r;
    }
    return !$best || $best['allow'];
}

// ---------------------------------------------------------------- HTML checks
function lead_attrs(string $s): array {
    $out = [];
    preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $s, $m, PREG_SET_ORDER);
    foreach ($m as $x) $out[strtolower($x[1])] = html_entity_decode(($x[2] ?? '') . ($x[3] ?? '') . ($x[4] ?? ''), ENT_QUOTES | ENT_HTML5);
    return $out;
}
function lead_tags(string $html, string $name): array {
    preg_match_all('/<' . $name . '\b([^>]*)>/i', $html, $m);
    return array_map('lead_attrs', $m[1]);
}
function lead_blocks(string $html, string $name): array {
    preg_match_all('/<' . $name . '\b[^>]*>(.*?)<\/' . $name . '>/is', $html, $m);
    return $m[1];
}
function lead_strip(string $html): string {
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(preg_replace('/<[^>]+>/', ' ', $html) ?? '', ENT_QUOTES | ENT_HTML5)) ?? '');
}
function lead_visible_text(string $html): string {
    $h = preg_replace('/<!--.*?-->/s', ' ', $html) ?? '';
    $h = preg_replace('/<(script|style|noscript|template|svg)\b.*?<\/\1>/is', ' ', $h) ?? '';
    return lead_strip($h);
}
function lead_copyright_year(string $text): ?int {
    preg_match_all('/(?:©|\(c\)|copyright)\s*(?:[^0-9]{0,20})?((?:19|20)\d{2})(?:\s*[-–—]\s*((?:19|20)\d{2}))?/iu', $text, $m, PREG_SET_ORDER);
    $ys = [];
    foreach ($m as $x) { $y = (int)(!empty($x[2]) ? $x[2] : $x[1]); if ($y >= 1995 && $y <= (int)gmdate('Y') + 1) $ys[] = $y; }
    return $ys ? max($ys) : null;
}
function lead_classify_url(?string $url): ?string {
    if (!$url) return null;
    if (str_contains($url, 'sites.google.com')) return 'Google Sites page';
    if (!lead_host_in($url, LEAD_SOCIAL_HOSTS)) return null;
    $d = lead_reg_domain($url);
    return ['facebook.com' => 'Facebook page', 'fb.com' => 'Facebook page', 'instagram.com' => 'Instagram profile', 'linktr.ee' => 'Linktree',
        'yelp.com' => 'Yelp page', 'business.site' => 'Google Business Profile site (discontinued)', 'g.page' => 'Google Business Profile link'][$d] ?? "$d page";
}

function lead_page_checks(string $html, string $finalUrl, array $headers = []): array {
    if (strlen($html) > 1500000) $html = substr($html, 0, 1500000);
    $lower = strtolower($html);
    $text = lead_visible_text($html);
    $hdr = implode(' ', array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers));
    $metas = lead_tags($html, 'meta');
    $meta = function (string $attr, string $re) use ($metas) { foreach ($metas as $m) if (preg_match($re, $m[$attr] ?? '')) return $m; return null; };
    $builders = ['Wix' => '/static\.wixstatic\.com|wix\.com|x-wix/i', 'Squarespace' => '/squarespace\.com|static1\.squarespace/i',
        'GoDaddy Website Builder' => '/img1\.wsimg\.com|GoDaddy Website Builder|websites\.godaddy/i', 'Weebly' => '/weebly\.com|editmysite\.com/i',
        'WordPress' => '/wp-content\/|wp-includes\//i', 'Duda' => '/dudaone|multiscreensite|dmcdn\.net|irp\.cdn-website\.com/i', 'Shopify' => '/cdn\.shopify\.com/i',
        'Webflow' => '/webflow\.(com|io)|data-wf-page/i', 'Google Sites' => '/sites\.google\.com/i', 'Joomla' => '/\/media\/jui\/|Joomla!/i',
        'Drupal' => '/Drupal\.settings|\/sites\/default\/files/i', 'Yola' => '/yola\.com|yolacdn/i', 'Jimdo' => '/jimdo/i', 'Homestead' => '/homestead\.com/i',
        'Hibu' => '/hibu\.com/i', 'Thryv' => '/thryv/i'];
    $builder = null;
    foreach ($builders as $n => $re) if (preg_match($re, $html) || preg_match($re, $hdr)) { $builder = $n; break; }
    $jquery = preg_match('/jquery[.-]?(\d+)\.(\d+)(?:\.(\d+))?(?:\.min)?\.js/', $lower, $jq) ? $jq[1] . '.' . $jq[2] : null;
    $old = [];
    if (!str_contains(substr($lower, 0, 400), '<!doctype html>')) $old[] = 'Old or missing HTML5 doctype';
    if (preg_match('/<(font|center|marquee|blink)\b/i', $html)) $old[] = 'Uses obsolete HTML tags (font/center/marquee)';
    if (preg_match('/<(frameset|frame)\b/i', $html)) $old[] = 'Uses HTML frames';
    if (preg_match('/\.swf\b|shockwave-flash/', $lower)) $old[] = 'Uses Flash';
    if (preg_match_all('/<table\b/i', $html) >= 3 && preg_match_all('/<(header|nav|section|footer|main|article)\b/i', $html) === 0) $old[] = 'Table-based page layout';
    if ($jquery && str_starts_with($jquery, '1.')) $old[] = "Old jQuery library ($jquery, from before 2016)";
    if (preg_match('/best viewed in|internet explorer/', $lower)) $old[] = "Mentions old browsers ('best viewed in…')";

    $title = mb_substr(lead_strip(lead_blocks($html, 'title')[0] ?? ''), 0, 200);
    $desc = $meta('name', '/^description$/i');
    $h1 = array_slice(array_values(array_filter(array_map('lead_strip', lead_blocks($html, 'h1')))), 0, 3);
    $anchors = array_values(array_filter(lead_tags($html, 'a'), fn($a) => !empty($a['href'])));
    $forms = lead_blocks($html, 'form');
    $contactForm = (bool)array_filter($forms, fn($f) => preg_match('/<textarea\b/i', $f) || preg_match('/<input\b[^>]*type\s*=\s*["\']?(email|tel)/i', $f));
    $bookingRes = ['Calendly' => '/calendly\.com/i', 'Housecall Pro' => '/housecallpro\.com/i', 'Jobber' => '/getjobber\.com|jobber\.com/i',
        'ServiceTitan' => '/servicetitan/i', 'Acuity' => '/acuityscheduling/i', 'Square Appointments' => '/squareup\.com\/appointments|square\.site/i',
        'Setmore' => '/setmore\.com/i', 'Vagaro' => '/vagaro\.com/i', 'Booksy' => '/booksy\.com/i', 'OpenTable' => '/opentable\.com/i', 'Resy' => '/resy\.com/i',
        'Toast' => '/toasttab\.com/i', 'Schedulicity' => '/schedulicity/i', 'Workiz' => '/workiz\.com/i',
        'Online booking text' => '/book (?:online|now|an appointment)|schedule (?:service|online|now)|request (?:an )?appointment|order online/i'];
    $booking = [];
    foreach ($bookingRes as $n => $re) if (preg_match($re, $html)) $booking[] = $n;
    $placeholders = ['/domain (?:is |may be )?for sale/i', '/buy this domain/i', '/parked (?:free|domain)/i', '/this domain is parked/i', '/coming soon/i',
        '/under construction/i', '/website is currently unavailable/i', '/account (?:has been )?suspended/i', '/future home of/i', '/default web page/i',
        '/welcome to nginx/i', '/apache2? (?:ubuntu )?default page/i'];
    $placeholder = null;
    foreach ($placeholders as $re) if (preg_match($re, mb_substr($text, 0, 3000), $pm)) { $placeholder = 'Page says “' . $pm[0] . '”'; break; }

    $fb = $ig = null; $internal = []; $baseDom = lead_reg_domain($finalUrl);
    foreach ($anchors as $a) {
        $href = lead_resolve_url($a['href'], $finalUrl);
        if (!$href) continue;
        $h = lead_host($href); $path = strtolower((string)parse_url($href, PHP_URL_PATH));
        $share = (bool)preg_match('#/(sharer|share|plugins|dialog|intent)#', $path);
        if (!$fb && str_ends_with($h, 'facebook.com') && trim($path, '/') !== '' && !$share) $fb = strtok($href, '?');
        if (!$ig && str_ends_with($h, 'instagram.com') && trim($path, '/') !== '' && !$share) $ig = strtok($href, '?');
        $clean = strtok($href, '#');
        if (preg_match('#^https?://#', $clean) && lead_reg_domain($clean) === $baseDom && !in_array($clean, $internal, true)) $internal[] = $clean;
    }
    $bad = '/\.(png|jpe?g|gif|svg|webp)$|example\.|sentry|wixpress|godaddy|domain\.com|email\.com|yourname|@2x/i';
    $emails = [];
    foreach ($anchors as $a) if (stripos($a['href'], 'mailto:') === 0) {
        $e = strtolower(trim(strtok(substr($a['href'], 7), '?')));
        if (filter_var($e, FILTER_VALIDATE_EMAIL) && !preg_match($bad, $e)) $emails[] = $e;
    }
    if (preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,24}/', $text, $em)) foreach ($em[0] as $e) if (!preg_match($bad, $e)) $emails[] = strtolower($e);
    $imgs = lead_tags($html, 'img');
    return [
        'title' => $title, 'meta_description' => $desc && trim($desc['content'] ?? '') !== '', 'h1' => $h1, 'viewport' => (bool)$meta('name', '/^viewport$/i'),
        'generator' => $meta('name', '/^generator$/i')['content'] ?? null, 'builder' => $builder, 'jquery' => $jquery, 'outdated_signals' => $old,
        'copyright_year' => lead_copyright_year($text), 'click_to_call' => (bool)array_filter($anchors, fn($a) => stripos($a['href'], 'tel:') === 0),
        'contact_form' => $contactForm, 'booking' => $booking,
        'analytics' => (bool)preg_match('/googletagmanager\.com|google-analytics\.com|gtag\(|fbq\(/', $html),
        'schema' => (bool)preg_match('/application\/ld\+json/i', $html),
        'local_business_schema' => (bool)preg_match('/"@type"\s*:\s*"[A-Za-z]*(LocalBusiness|Plumber|Electrician|HVACBusiness|RoofingContractor|Restaurant|HomeAndConstructionBusiness|AutomotiveBusiness|ProfessionalService)/', $html),
        'favicon' => (bool)array_filter(lead_tags($html, 'link'), fn($l) => stripos($l['rel'] ?? '', 'icon') !== false),
        'og_image' => (bool)$meta('property', '/^og:image$/i'), 'placeholder' => $placeholder, 'facebook_link' => $fb, 'instagram_link' => $ig,
        'emails' => array_values(array_unique($emails)), 'internal_links' => array_slice($internal, 0, 60),
        'contact_page' => current(array_filter($internal, fn($l) => stripos($l, 'contact') !== false)) ?: null,
        'image_count' => count($imgs), 'images_missing_alt' => count(array_filter($imgs, fn($i) => trim($i['alt'] ?? '') === '')),
        'word_count' => $text === '' ? 0 : count(preg_split('/\s+/', $text)), 'text_sample' => mb_substr($text, 0, 4000),
    ];
}

function lead_analyze_website(LeadFetcher $f, string $url, ?string $name, ?string $phoneNorm, bool $pagespeed): array {
    $a = ['url' => $url, 'checked_at' => gmdate('Y-m-d'), 'reachable' => false, 'errors' => []];
    try { lead_check_url($url); } catch (LeadBlocked $e) { $a['errors'][] = $e->getMessage(); $a['state'] = 'unreachable'; return $a; }
    try {
        if (!$f->robotsAllows($url)) { $a['state'] = 'blocked_robots'; $a['errors'][] = "robots.txt asks automated tools not to read this site; skipped detailed checks."; return $a; }
    } catch (Throwable $e) {}
    $res = null;
    try { $res = $f->fetch($url); }
    catch (Throwable $e) {
        $m = $e->getMessage();
        if ($m === 'timeout') $a['errors'][] = 'Site did not respond within 12 seconds';
        elseif (str_starts_with($m, 'ssl:')) { $a['errors'][] = 'SSL certificate error (browsers show a security warning)'; $a['ssl_error'] = true; }
        elseif ($e instanceof LeadBlocked) $a['errors'][] = $m;
        else $a['errors'][] = 'Could not load site: ' . mb_substr($m, 0, 120);
    }
    if (!$res) { $a['state'] = 'unreachable'; return $a; }
    $a += ['status' => $res['status'], 'final_url' => $res['url'], 'load_seconds' => $res['elapsed'], 'html_bytes' => $res['bytes'],
        'redirects' => count($res['redirects']), 'https' => str_starts_with($res['url'], 'https://'), 'ssl_error' => false];
    if ($res['status'] >= 400) { $a['state'] = 'unreachable'; $a['errors'][] = 'Homepage returned HTTP ' . $res['status']; return $a; }
    $a['reachable'] = true;
    if ($social = lead_classify_url($res['url'])) { $a['state'] = 'social_only'; $a['social_kind'] = $social; return $a; }

    $c = lead_page_checks($res['text'], $res['url'], $res['headers']);
    $internal = $c['internal_links']; $sample = $c['text_sample'];
    unset($c['internal_links'], $c['text_sample']);
    $a = array_merge($a, $c);
    $a['state'] = ($c['placeholder'] || $c['word_count'] < 25) ? 'placeholder' : 'has_website';
    if ($c['word_count'] < 25 && !$c['placeholder']) $a['placeholder'] = 'Almost no text content on the homepage';
    $digits = preg_replace('/\D/', '', $sample . ' ' . substr($res['text'], 0, 200000));
    $a['phone_on_site'] = $phoneNorm !== null && str_contains($digits, $phoneNorm);
    $a['name_match'] = $name ? round(lead_name_ratio($name, $c['title'] . ' ' . implode(' ', $c['h1']) . ' ' . mb_substr($sample, 0, 1500)), 2) : null;

    if (!$c['emails'] && $c['contact_page']) {
        try {
            if ($f->robotsAllows($c['contact_page'])) {
                $cr = $f->fetch($c['contact_page']);
                if ($cr['status'] < 400) {
                    $c2 = lead_page_checks($cr['text'], $cr['url']);
                    if ($c2['emails']) { $a['emails'] = $c2['emails']; $a['email_page'] = $cr['url']; }
                    $a['contact_form'] = $a['contact_form'] || $c2['contact_form'];
                    $a['booking'] = array_values(array_unique(array_merge($a['booking'], $c2['booking'])));
                }
            }
        } catch (Throwable $e) {}
    }
    if (!empty($a['emails']) && empty($a['email_page'])) $a['email_page'] = $res['url'];

    $broken = []; $checked = 0; $max = (int)lead_cfg('max_link_checks', 8);
    foreach ($internal as $link) {
        if ($checked >= $max) break;
        if (rtrim($link, '/') === rtrim($res['url'], '/') || preg_match('/\.(pdf|jpe?g|png|gif|zip|docx?)$/i', $link)) continue;
        try {
            if (!$f->robotsAllows($link)) continue;
            $lr = $f->fetch($link, 'HEAD', 200000, 400, 8);
            if (in_array($lr['status'], [403, 405, 501], true)) $lr = $f->fetch($link, 'GET', 200000, 400, 8);
            $checked++;
            if ($lr['status'] >= 400) $broken[] = ['url' => $link, 'status' => $lr['status']];
        } catch (Throwable $e) { $checked++; $broken[] = ['url' => $link, 'status' => 'no response']; }
    }
    $a['links_checked'] = $checked;
    $a['broken_links'] = $broken;
    if ($pagespeed && (lead_key('pagespeed') ?: lead_key('google_places')) !== '') $a['pagespeed'] = lead_pagespeed($res['url']);
    return $a;
}

function lead_pagespeed(string $url): array {
    try {
        $qs = http_build_query(['url' => $url, 'strategy' => 'mobile', 'category' => 'performance', 'key' => lead_key('pagespeed') ?: lead_key('google_places')]);
        [$code, $j] = lead_api('GET', lead_endpoint('pagespeed', 'https://www.googleapis.com') . '/pagespeedonline/v5/runPagespeed?' . $qs, [], null, 70);
        if ($code >= 400 || !$j) return ['error' => "PageSpeed returned $code"];
        $lh = $j['lighthouseResult'] ?? []; $au = $lh['audits'] ?? [];
        $s = $lh['categories']['performance']['score'] ?? null;
        return ['mobile_score' => $s === null ? null : (int)round($s * 100), 'lcp' => $au['largest-contentful-paint']['displayValue'] ?? null,
            'cls' => $au['cumulative-layout-shift']['displayValue'] ?? null, 'tbt' => $au['total-blocking-time']['displayValue'] ?? null];
    } catch (Throwable $e) { return ['error' => 'PageSpeed could not run']; }
}

// ---------------------------------------------------------------- web search verification (Brave or Firecrawl)
function lead_web_search(string $q): array {
    $p = lead_search_provider();
    if ($p === 'brave') {
        [$code, $j] = lead_api('GET', lead_endpoint('brave', 'https://api.search.brave.com') . '/res/v1/web/search?' . http_build_query(['q' => $q, 'count' => 10, 'country' => 'us']),
            ['Accept' => 'application/json', 'X-Subscription-Token' => lead_key('brave')], null, 20);
        if ($code >= 400 || !$j) throw new LeadApiError("Brave returned $code");
        return array_map(fn($x) => ['url' => $x['url'] ?? '', 'title' => $x['title'] ?? '', 'snippet' => $x['description'] ?? ''], $j['web']['results'] ?? []);
    }
    if ($p === 'firecrawl') {
        [$code, $j] = lead_api('POST', lead_endpoint('firecrawl', 'https://api.firecrawl.dev') . '/v2/search',
            ['Authorization' => 'Bearer ' . lead_key('firecrawl'), 'Content-Type' => 'application/json'], ['query' => $q, 'limit' => 10], 40);
        if ($code >= 400 || !$j) throw new LeadApiError("Firecrawl returned $code");
        $d = $j['data'] ?? [];
        if (isset($d['web'])) $d = $d['web'];
        return array_map(fn($x) => ['url' => $x['url'] ?? '', 'title' => $x['title'] ?? '', 'snippet' => $x['description'] ?? ''], is_array($d) ? $d : []);
    }
    return [];
}
function lead_find_candidates(string $name, ?string $city, ?string $state): array {
    $out = ['website_candidates' => [], 'facebook' => null, 'instagram' => null, 'error' => null];
    if (!lead_search_provider()) return $out;
    try { $results = lead_web_search(trim("\"$name\" $city $state")); }
    catch (Throwable $e) { $out['error'] = 'Web search failed: ' . $e->getMessage(); return $out; }
    $socialOk = function (string $u): bool {
        $path = strtolower((string)parse_url($u, PHP_URL_PATH));
        foreach (['/sharer', '/share', '/plugins', '/tr', '/dialog', '/events/', '/groups/', '/watch', '/hashtag', '/p/', '/reel', '/explore', '/stories', '/posts/', '/photos'] as $b) if (str_contains($path, $b)) return false;
        return trim($path, '/') !== '';
    };
    foreach ($results as $r) {
        $u = (string)$r['url'];
        $ratio = lead_name_ratio($name, $r['title'] . ' ' . $r['snippet'] . ' ' . $u);
        $h = lead_host($u);
        if (str_ends_with($h, 'facebook.com')) { if (!$out['facebook'] && $ratio >= 0.6 && $socialOk($u)) $out['facebook'] = $u; }
        elseif (str_ends_with($h, 'instagram.com')) { if (!$out['instagram'] && $ratio >= 0.6 && $socialOk($u)) $out['instagram'] = $u; }
        elseif ($u !== '' && !lead_host_in($u, LEAD_DIRECTORY_HOSTS) && !str_ends_with($h, '.gov')) {
            if ($ratio >= 0.6 || lead_name_ratio($name, (string)lead_reg_domain($u)) >= 0.5) $out['website_candidates'][] = $u;
        }
    }
    return $out;
}

// ---------------------------------------------------------------- scoring (1-100, every point explained)
function lead_score(array $lead, array $a): array {
    $r = [];
    $add = function (int $p, string $why) use (&$r) { $r[] = ['points' => $p, 'reason' => $why]; };
    $st = $lead['website_state'] ?? '';
    $year = (int)gmdate('Y');
    if (!empty($lead['business_status']) && $lead['business_status'] !== 'OPERATIONAL') $add(-100, 'Google lists this business as ' . strtolower(str_replace('_', ' ', $lead['business_status'])));
    switch ($st) {
        case 'none_verified': $add(55, 'No website on Google, and a web search found no other website'); break;
        case 'none_google_only': $add(48, 'No website listed on Google (not cross-checked with web search)'); break;
        case 'social_only': $add(55, "Its only 'website' is a " . ($a['social_kind'] ?? 'social media page')); break;
        case 'unreachable': $add(50, 'Website is down or broken: ' . implode('; ', array_slice($a['errors'] ?? [], 0, 2))); break;
        case 'placeholder': $add(50, 'Website is a placeholder: ' . ($a['placeholder'] ?? 'little or no content')); break;
        case 'blocked_robots': $add(10, "Has a website, but its robots.txt blocks automated checks, so quality wasn't measured"); break;
        case 'has_website': case 'found_unlisted':
            $add(8, 'Has a working website. Points below are for problems found on it');
            if ($st === 'found_unlisted') $add(6, "Website isn't linked on the Google Business Profile (missed traffic)");
            if (empty($a['viewport'])) $add(20, 'Not mobile-friendly: no mobile viewport tag');
            if (!empty($a['ssl_error'])) $add(12, "SSL certificate error: visitors see a 'Not secure' warning");
            elseif (empty($a['https'])) $add(10, "No HTTPS: browsers mark the site 'Not secure'");
            $cy = $a['copyright_year'] ?? null;
            if ($cy && $year - $cy >= 3) $add(12, "Copyright year is $cy: the site looks unmaintained");
            elseif ($cy && $year - $cy >= 1) $add(4, "Copyright year is $cy (slightly out of date)");
            $old = $a['outdated_signals'] ?? [];
            if ($old) $add(min(15, 6 * count($old)), 'Outdated technology: ' . implode(', ', array_slice($old, 0, 3)));
            if (in_array($a['builder'] ?? '', ['GoDaddy Website Builder', 'Weebly', 'Yola', 'Homestead', 'Jimdo', 'Google Sites', 'Hibu'], true)) $add(6, "Built with {$a['builder']}: a basic DIY builder");
            $ls = $a['load_seconds'] ?? 0;
            if ($ls > 5) $add(10, "Slow: homepage took {$ls}s to load"); elseif ($ls > 3) $add(5, "Somewhat slow: homepage took {$ls}s to load");
            $ps = $a['pagespeed']['mobile_score'] ?? null;
            if ($ps !== null && $ps < 50) $add(12, "Poor Google PageSpeed mobile score ($ps/100)"); elseif ($ps !== null && $ps < 70) $add(6, "Weak Google PageSpeed mobile score ($ps/100)");
            if (($a['html_bytes'] ?? 0) > 2500000) $add(4, 'Very heavy homepage');
            if (empty($a['click_to_call'])) $add(5, 'No click-to-call phone link');
            if (empty($a['contact_form']) && empty($a['booking'])) $add(6, 'No contact form or online booking');
            if (empty($a['title']) || empty($a['meta_description'])) $add(4, 'Missing basic SEO (page title or meta description)');
            if (empty($a['h1'])) $add(2, 'No main headline (H1)');
            if (empty($a['local_business_schema'])) $add(2, 'No local-business structured data for Google');
            $b = count($a['broken_links'] ?? []);
            if ($b) $add(min(9, 3 * $b), "$b broken link(s) out of " . ($a['links_checked'] ?? 0) . ' checked');
            break;
    }
    $rc = (int)($lead['review_count'] ?? 0);
    if ($rc >= 50) $add(10, "Established: $rc Google reviews"); elseif ($rc >= 15) $add(7, "Active: $rc Google reviews"); elseif ($rc >= 3) $add(3, "$rc Google reviews");
    if (($lead['rating'] ?? 0) >= 4.3 && $rc >= 5) $add(4, "Strong reputation ({$lead['rating']}★) worth showcasing");
    if (in_array($st, ['none_verified', 'none_google_only', 'social_only'], true) && (!empty($lead['facebook']) || !empty($lead['instagram']))) $add(8, 'Active on social media but no professional website');
    if (!empty($lead['phone'])) $add(2, 'Reachable by phone');
    $total = max(1, min(100, array_sum(array_column($r, 'points'))));
    usort($r, fn($x, $y) => abs($y['points']) <=> abs($x['points']));
    return [$total, $total >= 70 ? 'Hot' : ($total >= 45 ? 'Warm' : 'Cold'), $r];
}

// ---------------------------------------------------------------- opportunity summary (verified findings only)
function lead_template_pitch(array $l, array $a): string {
    $name = $l['name']; $cat = strtolower($l['category'] ?: 'local business'); $city = $l['city'] ?: 'the area'; $st = $l['website_state'];
    $rc = (int)($l['review_count'] ?? 0);
    $rep = $rc >= 5 ? " With $rc Google reviews" . ($l['rating'] ? " at {$l['rating']}★" : '') . ', customers already trust them, so the website is the weak link.' : '';
    $gaps = []; $wins = [];
    if ($st === 'none_verified' || $st === 'none_google_only') {
        $gaps[] = "$name has no website listed on Google";
        array_push($wins, "show up when people in $city search for $cat", 'turn searches into phone calls with a tap-to-call button', 'look more credible than competitors who only have a listing');
    } elseif ($st === 'social_only') {
        $gaps[] = "$name's only web presence is a " . ($a['social_kind'] ?? 'social media page');
        array_push($wins, 'own a site Google can rank, not just a social profile', 'capture quote requests 24/7 with a contact form', "look established to customers who don't use social media");
    } elseif ($st === 'unreachable' || $st === 'placeholder') {
        $gaps[] = "$name's website is " . ($st === 'unreachable' ? 'down or broken' : 'a placeholder page') . ', so visitors from Google hit a dead end';
        array_push($wins, 'stop losing customers who click through from Google', 'add a clear call button and quote form');
    } elseif ($st === 'blocked_robots') {
        return "$name has a website, but its robots.txt asks automated tools not to read it, so it wasn't analyzed. Review the site by hand before reaching out.$rep";
    } else {
        if ($st === 'found_unlisted') { $gaps[] = "$name's website isn't linked on its Google Business Profile, so Google searchers can't find it"; $wins[] = 'connect the site to Google so searchers can find it'; }
        if (empty($a['viewport'])) { $gaps[] = "the site isn't built for phones, where most local searches happen"; $wins[] = 'give mobile visitors a fast, easy-to-tap experience'; }
        if (!empty($a['ssl_error']) || empty($a['https'])) { $gaps[] = "browsers show a 'Not secure' warning"; $wins[] = 'remove the security warning that scares visitors off'; }
        if (!empty($a['copyright_year']) && $a['copyright_year'] <= (int)gmdate('Y') - 3) $gaps[] = "the footer still says {$a['copyright_year']}";
        if (!empty($a['outdated_signals'])) $gaps[] = "it's built on outdated technology";
        if (empty($a['click_to_call'])) $wins[] = 'get more calls with tap-to-call buttons';
        if (empty($a['contact_form']) && empty($a['booking'])) { $gaps[] = "there's no way to request a quote or book online"; $wins[] = 'book jobs online, even after hours'; }
        if (!empty($a['broken_links'])) $gaps[] = count($a['broken_links']) . ' link(s) on the site are broken';
        $ps = $a['pagespeed']['mobile_score'] ?? null;
        if ($ps !== null && $ps < 50) { $gaps[] = "Google rates its mobile speed $ps/100"; $wins[] = "load fast enough that visitors don't bounce"; }
        if (!$gaps) { $gaps[] = "$name's site works, with only minor issues found"; $wins[] = 'add the conversion features (quote forms, reviews, booking) that turn visits into jobs'; }
    }
    $wins[] = 'rank better on Google for local searches';
    $t = ucfirst($gaps[0]) . (count($gaps) > 1 ? '; ' . implode('; ', array_slice($gaps, 1, 2)) : '') . '.';
    return $t . $rep . ' A professionally designed website could help them ' . implode(', ', array_slice(array_values(array_unique($wins)), 0, 3)) . '.';
}
function lead_ai_pitch(array $l, array $a): string {
    $f = ['business' => $l['name'], 'category' => $l['category'], 'city' => $l['city'], 'website_state' => $l['website_state'], 'google_rating' => $l['rating'],
        'google_reviews' => $l['review_count'], 'has_facebook' => !empty($l['facebook']), 'has_instagram' => !empty($l['instagram'])];
    if (in_array($l['website_state'], ['has_website', 'found_unlisted'], true)) $f += ['mobile_friendly' => $a['viewport'] ?? null, 'https' => $a['https'] ?? null,
        'ssl_error' => $a['ssl_error'] ?? null, 'copyright_year' => $a['copyright_year'] ?? null, 'outdated_signals' => $a['outdated_signals'] ?? [],
        'load_seconds' => $a['load_seconds'] ?? null, 'pagespeed_mobile' => $a['pagespeed']['mobile_score'] ?? null, 'click_to_call' => $a['click_to_call'] ?? null,
        'contact_form' => $a['contact_form'] ?? null, 'online_booking' => $a['booking'] ?? [], 'broken_links' => count($a['broken_links'] ?? []),
        'builder' => $a['builder'] ?? null, 'website_linked_on_google' => $l['website_state'] !== 'found_unlisted'];
    elseif (in_array($l['website_state'], ['unreachable', 'placeholder', 'social_only'], true)) $f['website_problem'] = $a['social_kind'] ?? ($a['placeholder'] ?? implode('; ', array_slice($a['errors'] ?? [], 0, 2)));
    $system = 'You write short, specific sales-research notes for VERO Digital, a web design agency. Use ONLY the facts provided. Never invent numbers, features, awards, or claims. If a fact is missing, do not mention it. 2-3 sentences, plain English, no greeting, no sign-off, no exclamation marks. First say what the business is missing, then how a professional website would help (more calls, quote requests, credibility, bookings, Google visibility, mobile experience).';
    [$code, $j] = lead_api('POST', lead_endpoint('anthropic', 'https://api.anthropic.com') . '/v1/messages',
        ['x-api-key' => lead_key('anthropic'), 'anthropic-version' => '2023-06-01', 'Content-Type' => 'application/json'],
        ['model' => (string)lead_cfg('ai_model', 'claude-haiku-5-5'), 'max_tokens' => 300, 'system' => $system,
         'messages' => [['role' => 'user', 'content' => "Verified facts (JSON):\n" . json_encode($f, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]], 45);
    if ($code >= 400 || !$j) throw new LeadApiError("Claude returned $code");
    $t = '';
    foreach ($j['content'] ?? [] as $p) if (($p['type'] ?? '') === 'text') $t .= ' ' . $p['text'];
    return trim($t);
}
function lead_make_pitch(array $l, array $a): array {
    if (lead_key('anthropic') !== '' && $l['website_state'] !== 'blocked_robots') {
        try { $t = lead_ai_pitch($l, $a); if ($t !== '') return [$t, 'AI (Claude), from verified findings']; } catch (Throwable $e) {}
    }
    return [lead_template_pitch($l, $a), 'Template, from verified findings'];
}

// ---------------------------------------------------------------- build one lead
function lead_apply_site(array &$l, array $a): void {
    if (!empty($a['facebook_link']) && !$l['facebook']) { $l['facebook'] = $a['facebook_link']; $l['facebook_source'] = "Verified: linked from the business's own website"; }
    if (!empty($a['instagram_link']) && !$l['instagram']) { $l['instagram'] = $a['instagram_link']; $l['instagram_source'] = "Verified: linked from the business's own website"; }
    if (!empty($a['emails'])) { $l['email'] = $a['emails'][0]; $l['email_source'] = 'Verified: shown on ' . ($a['email_page'] ?? 'business website'); }
}
function lead_build(array $p, string $category, string $location, bool $pagespeed, ?string $siteSource = null): array {
    $f = new LeadFetcher();
    $l = ['place_id' => $p['place_id'], 'name' => $p['name'], 'address' => $p['address'], 'city' => $p['city'], 'state' => $p['state'], 'phone' => $p['phone'],
        'website' => $p['website'] ?? null, 'google_maps_url' => $p['google_maps_url'], 'rating' => $p['rating'], 'review_count' => $p['review_count'],
        'business_status' => $p['business_status'], 'google_type' => $p['google_type'], 'category' => $category, 'search_location' => $location,
        'google_fetched_at' => now_utc(), 'phone_norm' => lead_norm_phone($p['phone']),
        'phone_source' => $p['phone'] ? 'Verified: Google Business Profile' : 'Not found on Google',
        'email' => null, 'email_source' => 'Not found publicly', 'facebook' => null, 'facebook_source' => 'Not found',
        'instagram' => null, 'instagram_source' => 'Not found', 'domain' => null, 'website_source' => '', 'website_state' => ''];
    $a = [];
    $site = $p['website'] ?? null;
    $social = $site ? lead_classify_url($site) : null;
    if ($site && !$social) {
        $l['website_source'] = $siteSource ?: 'Verified: Google Business Profile';
        $l['domain'] = lead_reg_domain($site);
        $a = lead_analyze_website($f, $site, $l['name'], $l['phone_norm'], $pagespeed);
        $l['website_state'] = $a['state'] ?? 'unreachable';
        if (($a['state'] ?? '') === 'social_only') $social = $a['social_kind'];
        lead_apply_site($l, $a);
    }
    if (!$site || $social) {
        if ($social) {
            $l['website_state'] = 'social_only'; $l['website_source'] = "Google lists a $social as the website"; $l['domain'] = null;
            $a = ['state' => 'social_only', 'social_kind' => $social, 'url' => $site];
            if (stripos($site, 'facebook.com') !== false) { $l['facebook'] = $site; $l['facebook_source'] = 'Verified: listed on Google Business Profile'; }
            if (stripos($site, 'instagram.com') !== false) { $l['instagram'] = $site; $l['instagram_source'] = 'Verified: listed on Google Business Profile'; }
        } else {
            $l['website_state'] = 'none_google_only'; $l['website_source'] = 'No website listed on Google Business Profile';
            $a = ['state' => 'none_google_only'];
        }
        if ($prov = lead_search_provider()) {
            $cand = lead_find_candidates($l['name'], $l['city'], $l['state']);
            $ws = ['provider' => $prov, 'error' => $cand['error'], 'candidates_checked' => []];
            $a['web_search'] = $ws;
            $found = null;
            foreach (array_slice($cand['website_candidates'], 0, 2) as $u) {
                $a2 = lead_analyze_website($f, $u, $l['name'], $l['phone_norm'], $pagespeed);
                $ok = !empty($a2['reachable']) && (!empty($a2['phone_on_site']) || ($a2['name_match'] ?? 0) >= 0.8);
                $ws['candidates_checked'][] = ['url' => $u, 'matched' => $ok];
                if ($ok && in_array($a2['state'], ['has_website', 'placeholder'], true)) { $found = [$u, $a2]; break; }
            }
            $a['web_search'] = $ws;
            if ($found) {
                [$u, $a2] = $found; $a2['web_search'] = $ws; $a = $a2;
                $l['website'] = $u; $l['domain'] = lead_reg_domain($u);
                $l['website_state'] = $a2['state'] === 'has_website' ? 'found_unlisted' : 'placeholder';
                $l['website_source'] = !empty($a2['phone_on_site']) ? 'Verified: found by web search; page shows the same phone number' : 'Verified: found by web search; page matches the business name';
                lead_apply_site($l, $a2);
            } elseif (!$cand['error'] && $l['website_state'] === 'none_google_only') {
                $l['website_state'] = 'none_verified'; $l['website_source'] = 'Verified: none on Google, and none found by web search';
            }
            if ($cand['facebook'] && !$l['facebook']) { $l['facebook'] = $cand['facebook']; $l['facebook_source'] = 'Unverified: possible match from web search. Check before use'; }
            if ($cand['instagram'] && !$l['instagram']) { $l['instagram'] = $cand['instagram']; $l['instagram_source'] = 'Unverified: possible match from web search. Check before use'; }
        }
    }
    [$l['score'], $l['score_tier'], $l['score_reasons']] = lead_score($l, $a);
    $l['analysis'] = $a; $l['analyzed_at'] = now_utc();
    [$l['pitch'], $l['pitch_source']] = lead_make_pitch($l, $a);
    return $l;
}

// ---------------------------------------------------------------- storage
const LEAD_COLS = ['place_id', 'name', 'category', 'google_type', 'search_location', 'address', 'city', 'state', 'phone', 'phone_norm', 'phone_source',
    'email', 'email_source', 'website', 'domain', 'website_source', 'website_state', 'facebook', 'facebook_source', 'instagram', 'instagram_source',
    'google_maps_url', 'rating', 'review_count', 'business_status', 'analysis_json', 'score', 'score_tier', 'score_reasons_json', 'pitch', 'pitch_source',
    'google_fetched_at', 'analyzed_at'];

function lead_row_in(array $l): array {
    $r = $l;
    $r['analysis_json'] = json_encode($l['analysis'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $r['score_reasons_json'] = json_encode($l['score_reasons'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    foreach (['name' => 200, 'address' => 300, 'city' => 120, 'state' => 40, 'phone' => 40, 'email' => 190, 'website' => 500, 'domain' => 190,
        'facebook' => 500, 'instagram' => 500, 'google_maps_url' => 500, 'category' => 80, 'search_location' => 200, 'google_type' => 120,
        'phone_source' => 200, 'email_source' => 500, 'website_source' => 200, 'facebook_source' => 200, 'instagram_source' => 200] as $k => $n) {
        if (isset($r[$k]) && is_string($r[$k])) $r[$k] = mb_substr($r[$k], 0, $n);
    }
    return $r;
}
function lead_out(?array $r): ?array {
    if (!$r) return null;
    $r['analysis'] = json_decode((string)($r['analysis_json'] ?? 'null'), true);
    $r['score_reasons'] = json_decode((string)($r['score_reasons_json'] ?? '[]'), true) ?: [];
    unset($r['analysis_json'], $r['score_reasons_json']);
    foreach (['id', 'score', 'review_count'] as $k) if (isset($r[$k])) $r[$k] = (int)$r[$k];
    if (isset($r['rating'])) $r['rating'] = $r['rating'] === null ? null : (float)$r['rating'];
    $r['favorite'] = (int)($r['favorite'] ?? 0) === 1;
    return $r;
}
function lead_find_dup(PDO $db, ?string $placeId, ?string $phoneNorm, ?string $domain): ?array {
    foreach ([['place_id', $placeId, 'Google place ID'], ['phone_norm', $phoneNorm, 'phone number'], ['domain', $domain, 'website domain']] as [$col, $v, $why]) {
        if (!$v) continue;
        $st = $db->prepare("SELECT id FROM leads WHERE $col = ? LIMIT 1");
        $st->execute([$v]);
        if ($id = $st->fetchColumn()) return ['id' => (int)$id, 'why' => $why];
    }
    return null;
}
function lead_insert(PDO $db, array $l): int {
    $r = lead_row_in($l); $now = now_utc();
    $cols = array_merge(LEAD_COLS, ['status', 'favorite', 'notes', 'created_at', 'updated_at']);
    $vals = array_map(fn($c) => $r[$c] ?? null, LEAD_COLS);
    array_push($vals, 'New', 0, '', $now, $now);
    $db->prepare('INSERT INTO leads (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
    return (int)$db->lastInsertId();
}
function lead_update_analysis(PDO $db, int $id, array $l): void {
    $r = lead_row_in($l);
    $cols = array_values(array_diff(LEAD_COLS, ['place_id']));
    $sets = implode(',', array_map(fn($c) => "$c = ?", $cols));
    $vals = array_map(fn($c) => $r[$c] ?? null, $cols);
    $vals[] = now_utc(); $vals[] = $id;
    $db->prepare("UPDATE leads SET $sets, updated_at = ? WHERE id = ?")->execute($vals);
}
function lead_get(PDO $db, int $id): ?array {
    $st = $db->prepare('SELECT * FROM leads WHERE id = ?');
    $st->execute([$id]);
    $l = lead_out($st->fetch() ?: null);
    if ($l) { $m = lead_meeting_index($db); $l['meeting'] = lead_meeting_for($m, $l); }
    return $l;
}

// Leads <-> Meetings: a lead that booked a call (same phone or email) shows its meeting.
function lead_meeting_index(PDO $db): array {
    $ix = ['phone' => [], 'email' => []];
    foreach ($db->query("SELECT id, phone, email, status, start_at FROM meetings ORDER BY start_at DESC")->fetchAll() as $m) {
        $m['id'] = (int)$m['id'];
        $p = lead_norm_phone($m['phone']);
        if ($p) $ix['phone'][substr($p, -10)] ??= $m;
        if ($m['email'] !== '') $ix['email'][strtolower($m['email'])] ??= $m;
    }
    return $ix;
}
function lead_meeting_for(array $ix, array $l): ?array {
    $p = lead_norm_phone($l['phone'] ?? null);
    $m = ($p ? ($ix['phone'][substr($p, -10)] ?? null) : null) ?? (!empty($l['email']) ? ($ix['email'][strtolower($l['email'])] ?? null) : null);
    return $m ? ['id' => $m['id'], 'status' => $m['status'], 'start_at' => $m['start_at']] : null;
}

function lead_list(PDO $db, array $q): array {
    $w = []; $a = [];
    if (($s = trim((string)($q['q'] ?? ''))) !== '') { $w[] = '(name LIKE ? OR address LIKE ? OR phone LIKE ? OR email LIKE ? OR website LIKE ? OR notes LIKE ?)'; array_push($a, ...array_fill(0, 6, '%' . $s . '%')); }
    foreach (['category', 'city', 'status'] as $k) if (!empty($q[$k])) { $w[] = "$k = ?"; $a[] = (string)$q[$k]; }
    if (!empty($q['favorite'])) $w[] = 'favorite = 1';
    if (!empty($q['min_score'])) { $w[] = 'score >= ?'; $a[] = (int)$q['min_score']; }
    if (!empty($q['website_state'])) {
        $ws = array_values(array_intersect(explode(',', (string)$q['website_state']), array_keys(LEAD_WEBSITE_LABELS)));
        if ($ws) { $w[] = 'website_state IN (' . implode(',', array_fill(0, count($ws), '?')) . ')'; array_push($a, ...$ws); }
    }
    $sort = in_array($q['sort'] ?? '', ['score', 'name', 'city', 'review_count', 'created_at', 'status'], true) ? $q['sort'] : 'score';
    $dir = strtolower((string)($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $st = $db->prepare('SELECT * FROM leads' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY $sort $dir, id DESC LIMIT 3000");
    $st->execute($a);
    $ix = lead_meeting_index($db);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $l = lead_out($r);
        $l['meeting'] = lead_meeting_for($ix, $l);
        if (!empty($q['booked']) && !$l['meeting']) continue;
        $out[] = $l;
    }
    return $out;
}
function lead_slim(array $l): array {
    $keep = ['id', 'name', 'category', 'city', 'state', 'phone', 'email', 'website', 'website_state', 'facebook', 'instagram', 'rating', 'review_count',
        'score', 'score_tier', 'status', 'favorite', 'created_at', 'google_maps_url', 'meeting'];
    return array_intersect_key($l, array_flip($keep));
}
function lead_summary(PDO $db): array {
    $one = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
    $by = [];
    foreach ($db->query('SELECT status, COUNT(*) n FROM leads GROUP BY status')->fetchAll() as $r) $by[$r['status']] = (int)$r['n'];
    $cats = array_column($db->query('SELECT DISTINCT category FROM leads WHERE category IS NOT NULL ORDER BY category')->fetchAll(), 'category');
    $cities = array_column($db->query("SELECT DISTINCT city FROM leads WHERE city IS NOT NULL AND city <> '' ORDER BY city")->fetchAll(), 'city');
    return ['total' => $one('SELECT COUNT(*) FROM leads'), 'hot' => $one('SELECT COUNT(*) FROM leads WHERE score >= 70'),
        'hot_new' => $one("SELECT COUNT(*) FROM leads WHERE score >= 70 AND status = 'New'"),
        'no_site' => $one("SELECT COUNT(*) FROM leads WHERE website_state IN ('none_verified','none_google_only','social_only')"),
        'favorites' => $one('SELECT COUNT(*) FROM leads WHERE favorite = 1'), 'by_status' => $by, 'categories' => $cats, 'cities' => $cities];
}

// ---------------------------------------------------------------- export
function lead_export_rows(array $leads): array {
    $safe = function ($v) {
        if ($v === null) return '';
        if (is_bool($v)) return $v ? 'Yes' : '';
        if (is_string($v) && $v !== '' && (strpbrk($v[0], "=@\t\r") !== false || (strpbrk($v[0], '+-') !== false && !preg_match('/^[+-][\d\s().-]+$/', $v)))) return "'" . $v;
        return $v;
    };
    $head = ['Score', 'Tier', 'Business', 'Category', 'Status', 'Favorite', 'Meeting', 'Phone', 'Phone source', 'Email', 'Email source', 'Address', 'City', 'State',
        'Website status', 'Website', 'Website source', 'Facebook', 'Facebook source', 'Instagram', 'Instagram source', 'Google rating', 'Google reviews',
        'Google Maps', 'Score reasons', 'Opportunity summary', 'Notes', 'Analyzed'];
    $rows = [$head];
    foreach ($leads as $l) {
        $rows[] = array_map($safe, [$l['score'], $l['score_tier'], $l['name'], $l['category'], $l['status'], $l['favorite'] ? 'Yes' : '',
            $l['meeting'] ? ucfirst(str_replace('_', ' ', $l['meeting']['status'])) . ' ' . $l['meeting']['start_at'] . ' UTC' : '',
            $l['phone'], $l['phone_source'], $l['email'], $l['email_source'], $l['address'], $l['city'], $l['state'],
            LEAD_WEBSITE_LABELS[$l['website_state']] ?? $l['website_state'], $l['website'], $l['website_source'], $l['facebook'], $l['facebook_source'],
            $l['instagram'], $l['instagram_source'], $l['rating'], $l['review_count'], $l['google_maps_url'],
            implode(' | ', array_map(fn($r) => ($r['points'] > 0 ? '+' : '') . $r['points'] . ' ' . $r['reason'], $l['score_reasons'])),
            $l['pitch'], $l['notes'], $l['analyzed_at']]);
    }
    return $rows;
}
function lead_csv(array $rows): string {
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, "\xEF\xBB\xBF");
    foreach ($rows as $r) fputcsv($fh, $r, ',', '"', '');
    rewind($fh);
    return (string)stream_get_contents($fh);
}
function lead_xlsx(array $rows): string {
    $col = function (int $i): string { $s = ''; $i++; while ($i) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; };
    $x = fn($s) => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $data = '';
    foreach ($rows as $ri => $r) {
        $data .= '<row r="' . ($ri + 1) . '">';
        foreach ($r as $ci => $v) {
            $ref = $col($ci) . ($ri + 1); $s = $ri === 0 ? ' s="1"' : '';
            if (is_int($v) || is_float($v)) $data .= "<c r=\"$ref\"$s><v>$v</v></c>";
            elseif ($v !== '') $data .= "<c r=\"$ref\" t=\"inlineStr\"$s><is><t xml:space=\"preserve\">" . $x($v) . '</t></is></c>';
        }
        $data .= '</row>';
    }
    $last = $col(count($rows[0]) - 1) . count($rows);
    $widths = [7, 7, 30, 14, 13, 8, 22, 16, 26, 26, 30, 36, 14, 6, 22, 32, 34, 32, 34, 32, 34, 8, 8, 30, 60, 70, 30, 20];
    $cols = '';
    foreach ($widths as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Leads" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0E0E12"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane xSplit="3" ySplit="1" topLeftCell="D2" activePane="bottomRight" state="frozen"/></sheetView></sheetViews><cols>' . $cols . '</cols><sheetData>' . $data . '</sheetData><autoFilter ref="A1:' . $last . '"/></worksheet>',
    ];
    // Minimal ZIP (stored, no compression) so no PHP extension is needed.
    $out = ''; $cd = ''; $n = 0;
    foreach ($files as $name => $content) {
        $crc = crc32($content); $len = strlen($content); $off = strlen($out);
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, 0, 0, $crc, $len, $len, strlen($name), 0) . $name . $content;
        $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, 0, 0, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $off) . $name;
        $n++;
    }
    return $out . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($cd), strlen($out), 0);
}
