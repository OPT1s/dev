<?php

function usage()
{
    echo <<<STR
Usage: php ./createCampaignV2.php <domain>

STR;
}

// credentials for Binom
$entrypoint = getenv('TRACKER_ENTRYPOINT');
$apiKey = getenv('TRACKER_API_KEY');
$accessForUserId = getenv('USER_ID_FOR_OPEN_ACCESS');

// check for exists needed params
if (empty($entrypoint) || empty($apiKey)) {
    throw new RuntimeException("Cant find entrypoint and api key in environment");
}

if ($argc < 2) {
    usage();
    exit(1);
}

// CREATE CAMPAIGN BLOCK

$domain = $argv[1];
$keyword = preg_replace('/[^A-Za-z0-9]/', '', $domain);

//Check if campaign with this name exists
$campaigns = sendRequest(
    "$entrypoint/public/api/v1/campaign/short/info",
    "GET",
    "",
    $apiKey
);

foreach ($campaigns as $campaign) {
    if ($campaign['name'] === $domain) {
        throw new RuntimeException('Campaign with same name already exist!');
    }

    if ($campaign['key'] === $keyword) {
        throw new RuntimeException('Campaign with same keyword already exist!');
    }
}

// get GA traffic source
$trafficSources = sendRequest(
    "$entrypoint/public/api/v1/traffic_source/list/filtered",
    "GET",
    "",
    $apiKey
);
$GATraffic = $trafficSources[array_search('GA', array_column($trafficSources, 'name'))];

// get default domain for send data to tracker
$domains = sendRequest(
    "$entrypoint/public/api/v1/domains",
    "GET",
    "",
    $apiKey
);
$domainTracker = $domains[array_search(true, array_column($domains, 'isDefault'))];

// CREATE LANDING
// create tmp file with url content
$content = file_get_contents("https://$domain");
$tmp = tempnam(sys_get_temp_dir(), 'POST');
file_put_contents($tmp, $content);

// upload file as index.php to Binom
$fileUpload = sendRequest(
    "$entrypoint/public/api/v1/landing/upload",
    "POST",
    ["file" => curl_file_create($tmp, "application/x-php", "index.php") ],
    $apiKey
);
// create landing with uploaded file
$landingIndex = sendRequest(
    "$entrypoint/public/api/v1/landing/integrated",
    "POST",
    json_encode(["name" => "$domain white", "path" => $fileUpload["landing_file"]]),
    $apiKey
);
//  END CREATE LANDING

// create campaign array
$campaign =  [
    "name" => $domain,
    "trafficSourceId" => $GATraffic["id"],
    "costModel" => "CPC",
    "currency" => "USD",
    "isAuto" => true,
    "hideReferrerType" => "NONE",
    "domainUuid" => $domainTracker["id"],
    "distributionType" => "NORMAL",
    "customRotation" => [
        "defaultPaths" => [
            [
                "name" => "White",
                "enabled" => true,
                "weight" => 100,
                "landings" => [
                    [
                        "id" => $landingIndex["id"],
                        "enabled" => true,
                        "weight" => 100
                    ]
                ],
                "offers" => [
                    [
                        "offerId" => 0,
                        "campaignId" => 0,
                        "directUrl" => "https://" . $domain,
                        "enabled" => true,
                        "weight" => 100
                    ]
                ]
            ]
        ],
        "rules" => [],
    ],
    "campaignSettings" => [
        "s2sPostback" => null,
        "postbackPercent" => 100,
        "trafficLossPercent" => 0,
        "payoutPercent" => 100,
        "ea" => 100,
        "lpPixel" => null
    ],
];

// create binom campaign
$binomCampaign = sendRequest(
    "$entrypoint/public/api/v1/campaign",
    "POST",
    json_encode($campaign),
    $apiKey
);
// END CREATE BINOM CAMPAIGN

// GRANT OF ACCESS TO THE USER
if ($accessForUserId > 0) {
    // get user access list
    $userData = sendRequest(
        "$entrypoint/public/api/v1/identity/$accessForUserId",
        "GET",
        "",
        $apiKey
    );
    $permissions = $userData["permissions"];

    // check if exists already
    $campaignBlockId = array_search("CAMPAIGN", array_column($permissions, "subject"));
    $landingBlockId = array_search("LANDING", array_column($permissions, "subject"));
    $currentCampaignExists = array_search(
        $binomCampaign["id"],
        array_column($permissions[$campaignBlockId]["modify"], "id")
    );
    $currentLandingExists = array_search(
        $landingIndex["id"],
        array_column($permissions[$landingBlockId]["modify"], "id")
    );

    // grant access for landing and campaign
    if ($currentCampaignExists === false || $currentLandingExists === false) {
        // transform permission list for put request
        foreach ($permissions as $key => $permission) {
            $newPermissions[$key] = [
                "accessLevel" => $permission["accessLevel"],
                "subject" => $permission["subject"],
                "readUuids" => array_column($permission["read"], "id"),
                "modifyUuids" => array_column($permission["modify"], "id"),
                "readGroupUuids" => array_column($permission["readGroups"], "id"),
                "modifyGroupUuids" => array_column($permission["modifyGroups"], "id")
            ];
        }
        // grant access for campaign
        if ($currentCampaignExists === false) {
            $newPermissions[$campaignBlockId]["modifyUuids"][] = $binomCampaign["id"];
        }
        // grant access for landing
        if ($currentLandingExists === false) {
            $newPermissions[$landingBlockId]["modifyUuids"][] = $landingIndex["id"];
        }
        // save access list
        $userData = sendRequest(
            "$entrypoint/public/api/v1/user/$accessForUserId/permissions",
            "PUT",
            json_encode(["permissions" => $newPermissions]),
            $apiKey
        );
    }
}
// END GRANT OF ACCESS TO THE USER

// function for send request
function sendRequest(string $entrypoint, string $method, $data, string $apiKey): array
{
    if ($method === 'GET') {
        $url = "{$entrypoint}?" . http_build_query($data === "" ? [] : $data);
    } elseif ($method === 'POST' || $method === "PUT") {
        $url = $entrypoint;
    } else {
        throw new BadMethodCallException();
    }
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            "Api-key: " . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $data,
    ]);

    $response = curl_exec($curl);

    if (curl_errno($curl)) {
        throw new Exception('CURL error: ' . curl_error($curl));
    }

    $result = json_decode($response, true);

    if (array_key_exists('errors', $result)) {
        throw new Exception('Error: ' . $result['errors']);
    }

    return $result;
}
