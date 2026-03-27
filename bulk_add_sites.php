<?php
define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';

// Override DB_HOST for CLI if needed
$host = (DB_HOST === 'localhost') ? '127.0.0.1' : DB_HOST;

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

$sites = [
    ['name' => 'Aapka Furniture', 'url' => 'https://aapkafurniture.com'],
    ['name' => 'Ambrosia by HS', 'url' => 'https://ambrosiabyhs.com'],
    ['name' => 'Artlab DCI', 'url' => 'https://artlab-dci.ca'],
    ['name' => 'Astrosaurab', 'url' => 'https://astrosaurab.com'],
    ['name' => 'Consult Sex Problems', 'url' => 'https://consultsexproblems.com'],
    ['name' => 'Euclidee PMS', 'url' => 'https://euclideepms.in'],
    ['name' => 'Euclidee Software Solutions', 'url' => 'https://euclideesoftwaresolutions.com'],
    ['name' => 'Euclidee Software Solutions IN', 'url' => 'https://euclideesoftwaresolutions.in'],
    ['name' => 'Euclidee Solutions', 'url' => 'https://euclideesolutions.com'],
    ['name' => 'Euclide Software Solutions', 'url' => 'https://euclidesoftwaresolutions.com'],
    ['name' => 'Euclide Solutions CO IN', 'url' => 'https://euclidesolutions.co.in'],
    ['name' => 'Euclide Solutions', 'url' => 'https://euclidesolutions.com'],
    ['name' => 'First State Endo', 'url' => 'https://firststateendo.com'],
    ['name' => 'Flixir Solutions', 'url' => 'https://flixirsolutions.com'],
    ['name' => 'GBM Force', 'url' => 'https://gbmforce.in'],
    ['name' => 'Gurdwara of Delaware', 'url' => 'https://gurdwaraofdelaware.com'],
    ['name' => 'KLE JGMMMC Hospital', 'url' => 'https://hospital.klejgmmmc.edu.in'],
    ['name' => 'KIIF Innovation', 'url' => 'https://kiifinnovation.com'],
    ['name' => 'KLE Homoeo', 'url' => 'https://klehomoeo.edu.in'],
    ['name' => 'KLE JGMMMC', 'url' => 'https://klejgmmmc.edu.in'],
    ['name' => 'KLE Pharm', 'url' => 'https://klepharm.edu'],
    ['name' => 'Lumen Luxe', 'url' => 'https://lumen-luxe.com'],
    ['name' => 'Lumina Consultancy', 'url' => 'https://luminaconsultancy.in'],
    ['name' => 'Mank Consultant', 'url' => 'https://mankconsultant.com'],
    ['name' => 'Munish Verma', 'url' => 'https://munishverma.net'],
    ['name' => 'News Next', 'url' => 'https://newsnext.in'],
    ['name' => 'Nuttall Brown', 'url' => 'https://nuttallbrown.com'],
    ['name' => 'OM Digital Solutions', 'url' => 'https://omdigitalsolutions.com'],
    ['name' => 'Praveen Yoga', 'url' => 'https://praveenyoga.com'],
    ['name' => 'Praveen Yoga Academy', 'url' => 'https://praveenyogaacademy.com'],
    ['name' => 'Sachin NS Sharma Products', 'url' => 'https://products.sachinnssharma.com'],
    ['name' => 'Punjab Post Channel', 'url' => 'https://punjabpostchannel.com'],
    ['name' => 'Ralh Construction', 'url' => 'https://ralhconstruction.com'],
    ['name' => 'Sachin NS Sharma', 'url' => 'https://sachinnssharma.com'],
    ['name' => 'Simple Heals', 'url' => 'https://simpleheals.com'],
    ['name' => 'Spacelines', 'url' => 'https://spacelines.in'],
    ['name' => 'Staging Server Link', 'url' => 'https://stagingserverlink.com'],
    ['name' => 'Sachin NS Sharma Testimonials', 'url' => 'https://testimonials.sachinnssharma.com'],
    ['name' => 'The Alpha People', 'url' => 'https://thealphapeople.org'],
    ['name' => 'The Cosmic River', 'url' => 'https://thecosmicriver.com'],
    ['name' => 'The Country Clay', 'url' => 'https://thecountryclay.com'],
    ['name' => 'The Dawk', 'url' => 'https://thedawk.in'],
    ['name' => 'The Festival Sale', 'url' => 'https://thefestivalsale.com'],
    ['name' => 'Vandys Tutoring', 'url' => 'https://vandystutoring.in'],
    ['name' => 'Sachin NS Sharma Global', 'url' => 'https://sachinnssharmaglobal.com'],
    ['name' => 'Power My Success', 'url' => 'https://powermysuccess.sachinnssharma.com'],
    ['name' => 'GBM Force Web App', 'url' => 'https://gbmforce.web.app'],
    ['name' => 'GBM Force Company', 'url' => 'https://gbmforce-company.web.app'],
    ['name' => 'GBM Force Partner', 'url' => 'https://gbmforce-partner.web.app'],
    ['name' => 'GBM Force Staff', 'url' => 'https://gbmforce-partnerstaff.web.app'],
    ['name' => 'GBM Force Vendor', 'url' => 'https://gbmforce-vendor.web.app'],
    // KAHER section
    ['name' => 'KAHER Apply', 'url' => 'https://apply.kaher.edu.in', 'tags' => 'KAHER'],
    ['name' => 'KAHER Careers', 'url' => 'https://careers.kaher.edu.in', 'tags' => 'KAHER'],
    ['name' => 'KAHER DMS', 'url' => 'https://dms.kaher.edu.in', 'tags' => 'KAHER'],
    ['name' => 'ESS Staging', 'url' => 'https://ess.stagingserverlink.com', 'tags' => 'Staging'],
    ['name' => 'KAHER IEC', 'url' => 'https://iec.kaher.edu.in', 'tags' => 'KAHER'],
    ['name' => 'KAHER Incentive', 'url' => 'https://incentive.kaher.edu.in', 'tags' => 'KAHER'],
    ['name' => 'KAHER Main', 'url' => 'https://kaher.edu.in', 'tags' => 'KAHER'],
    ['name' => 'KAHER KCPT', 'url' => 'https://kcpt.kaher.edu.in', 'tags' => 'KAHER'],
    ['name' => 'KAHER Minio', 'url' => 'https://minio.kaher.edu.in', 'tags' => 'KAHER'],
];

echo "Starting bulk import...\n";
$added = 0;
$skipped = 0;

$checkStmt = $pdo->prepare('SELECT id FROM sites WHERE url = ?');
$insStmt   = $pdo->prepare('INSERT INTO sites (name, url, check_type, expected_status, is_active, tags) VALUES (?, ?, ?, ?, ?, ?)');

foreach ($sites as $s) {
    // Check if URL exists
    $checkStmt->execute([$s['url']]);
    if ($checkStmt->fetch()) {
        echo "Skipping (exists): {$s['url']}\n";
        $skipped++;
        continue;
    }

    $insStmt->execute([$s['name'], $s['url'], 'http', 200, 1, $s['tags'] ?? null]);
    echo "Added: {$s['name']} ({$s['url']})\n";
    $added++;
}

echo "\nDone! Added: $added, Skipped: $skipped\n";


echo "\nDone! Added: $added, Skipped: $skipped\n";
