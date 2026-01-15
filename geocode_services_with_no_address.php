<?php

/**
 * Splynx API Client Script - Geocode Services with No Coordinates
 *
 * This script retrieves active Splynx customers and their internet services,
 * then geocodes only those services that have no existing geo coordinates.
 * It does not modify or reference additional_attributes (install street/town).
 */

require_once 'config.php';

/**
 * Splynx API Client Class
 */
class SplynxApiClient
{
    private $apiUrl;
    private $apiKey;
    private $apiSecret;
    private $googleApiKey;
    private $countryCode;

    // Static properties for Nominatim rate-limiting
    private static $lastNominatimRequestTime = 0;
    private const NOMINATIM_REQUEST_INTERVAL = 1000000; // 1 second in microseconds

    // State for Google API key validity
    private $googleApiKeyIsValid = true;

    /**
     * Constructor
     */
    public function __construct($apiUrl, $apiKey, $apiSecret, $googleApiKey = null, $countryCode = 'nz')
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->googleApiKey = $googleApiKey;
        $this->countryCode = $countryCode;
    }

    /**
     * Makes a GET request to the Splynx API using Basic Authentication.
     */
    public function get($path, $params = [])
    {
        $queryString = http_build_query($params);
        $fullUrl = $this->apiUrl . '/' . ltrim($path, '/') . ($queryString ? '?' . $queryString : '');

        $authHeader = 'Basic ' . base64_encode($this->apiKey . ':' . $this->apiSecret);

        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $authHeader,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Splynx-API-Client');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        } else {
            echo "API GET request to {$fullUrl} failed with HTTP code {$httpCode}: " . ($response ?: 'No response body') . "\n";
            return null;
        }
    }

    /**
     * Makes a PUT request to the Splynx API to update a resource.
     */
    public function put($path, $data)
    {
        $fullUrl = $this->apiUrl . '/' . ltrim($path, '/');
        
        $authHeader = 'Basic ' . base64_encode($this->apiKey . ':' . $this->apiSecret);

        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $authHeader,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Splynx-API-Client');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 202) {
            return true;
        } else {
            echo "API PUT request to {$fullUrl} failed with HTTP code {$httpCode}: " . ($response ?: 'No response body') . "\n";
            return false;
        }
    }

    /**
     * Retrieves latitude and longitude from an address using the Nominatim API.
     */
    public function getCoordinatesFromAddressOSM($address)
    {
        $currentTime = microtime(true);
        $elapsedTime = $currentTime - self::$lastNominatimRequestTime;

        if ($elapsedTime < self::NOMINATIM_REQUEST_INTERVAL / 1000000) {
            $sleepTime = (self::NOMINATIM_REQUEST_INTERVAL / 1000000) - $elapsedTime;
            usleep($sleepTime * 1000000);
        }

        self::$lastNominatimRequestTime = microtime(true);

        $nominatimUrl = 'https://nominatim.openstreetmap.org/search';
        
        $params = [
            'q' => $address,
            'format' => 'json',
            'limit' => 1,
            'countrycodes' => $this->countryCode
        ];
        
        $fullUrl = $nominatimUrl . '?' . http_build_query($params);

        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Splynx-API-Client/1.0 (contact@your-email.com)');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (!empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
                return ['lat' => $data[0]['lat'], 'lon' => $data[0]['lon']];
            }
        }
        
        return null;
    }

    /**
     * Retrieves latitude and longitude from an address using the Google Geocoding API.
     */
    public function getCoordinatesFromAddressGoogle($address)
    {
        if (!$this->googleApiKeyIsValid) {
            echo "Skipping Google Geocoding API call due to a previous invalid key.\n";
            return null;
        }
        
        if (empty($this->googleApiKey)) {
            echo "Google Geocoding API key is not configured.\n";
            $this->googleApiKeyIsValid = false;
            return null;
        }

        $googleUrl = 'https://maps.googleapis.com/maps/api/geocode/json';
        
        $params = [
            'address' => $address,
            'key' => $this->googleApiKey,
            'components' => 'country:' . $this->countryCode
        ];
        
        $fullUrl = $googleUrl . '?' . http_build_query($params);

        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        
        if ($data['status'] !== 'OK') {
            echo "Google Geocoding API error: " . ($data['error_message'] ?? 'Unknown error') . ".\n";
            
            if ($data['status'] === 'REQUEST_DENIED') {
                echo "The Google API key appears to be invalid. Disabling further attempts.\n";
                $this->googleApiKeyIsValid = false;
            }
            return null;
        }
        
        if (!empty($data['results']) && isset($data['results'][0]['geometry']['location'])) {
            $location = $data['results'][0]['geometry']['location'];
            return ['lat' => $location['lat'], 'lon' => $location['lng']];
        }

        return null;
    }
}

echo "Initializing Splynx API client with Basic Authentication...\n";
$splynx = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret, $googleApiKey, $geocodingCountryCode);

echo "\nRetrieving all active customers...\n";

$customerSearchParams = [
    'main_attributes' => [
        'status' => 'active'
    ]
];

$customers = $splynx->get('admin/customers/customer', $customerSearchParams);

if ($customers !== null) {
    if (!empty($customers)) {
        echo "\nFound " . count($customers) . " active customers:\n";
        
        $servicesProcessed = 0;
        $servicesUpdated = 0;
        $servicesSkipped = 0;
        
        $updatedServices = [];
        $failedServices = [];
        
        foreach ($customers as $customer) {
            $customerHasServicesNeedingGeocode = false;
            
            if (isset($customer['id'])) {
                $serviceEndpoint = 'admin/customers/customer/' . $customer['id'] . '/internet-services';
                $internetServices = $splynx->get($serviceEndpoint);

                if ($internetServices !== null && !empty($internetServices)) {
                    // First pass: check if any services need geocoding
                    foreach ($internetServices as $service) {
                        if ($service['status'] === 'active') {
                            $geoMarker = $service['geo']['marker'] ?? null;
                            if (empty($geoMarker)) {
                                $customerHasServicesNeedingGeocode = true;
                                break;
                            }
                        }
                    }
                    
                    // Only display customer info if they have services needing geocoding
                    if ($customerHasServicesNeedingGeocode) {
                        echo "-------------------------------------------\n";
                        echo "Customer ID: " . ($customer['id'] ?? 'N/A') . "\n";
                        echo "Name: " . ($customer['name'] ?? 'N/A') . "\n";
                        echo "Login: " . ($customer['login'] ?? 'N/A') . "\n";
                    }
                    
                    foreach ($internetServices as $service) {
                        if ($service['status'] === 'active') {
                            $servicesProcessed++;
                            
                            // Check if service has existing coordinates
                            $geoMarker = $service['geo']['marker'] ?? null;
                            $geoAddress = $service['geo']['address'] ?? null;
                            
                            if (!empty($geoMarker)) {
                                $servicesSkipped++;
                                continue;
                            }
                            
                            echo "  Service ID: " . ($service['id'] ?? 'N/A') . "\n";
                            echo "    IPv4: " . ($service['ipv4'] ?? 'N/A') . "\n";
                            
                            // Service has no coordinates - check if it has an address to geocode
                            if (empty($geoAddress)) {
                                // Fallback to customer's address
                                $customerStreet = $customer['street_1'] ?? null;
                                $customerCity = $customer['city'] ?? null;
                                
                                if (!empty($customerStreet) && !empty($customerCity)) {
                                    $geoAddress = $customerStreet . ', ' . $customerCity;
                                    echo "    No service address found. Using customer address: {$geoAddress}\n";
                                } else {
                                    echo "    Status: Skipped (no address available for geocoding)\n";
                                    $servicesSkipped++;
                                    $failedServices[] = [
                                        'customer_name' => $customer['name'] ?? 'N/A',
                                        'service_id' => $service['id'] ?? 'N/A',
                                        'reason' => 'No address available'
                                    ];
                                    continue;
                                }
                            } else {
                                echo "    Geo Address: {$geoAddress}\n";
                            }
                            
                            echo "    Attempting to geocode...\n";
                            
                            // Try Nominatim first
                            $osmCoords = $splynx->getCoordinatesFromAddressOSM($geoAddress);
                            $newMarker = null;
                            
                            if ($osmCoords !== null) {
                                $newMarker = $osmCoords['lat'] . ',' . $osmCoords['lon'];
                                echo "    Nominatim geocoding successful: {$newMarker}\n";
                            } else {
                                echo "    Nominatim failed. Trying Google Geocoding API...\n";
                                $googleCoords = $splynx->getCoordinatesFromAddressGoogle($geoAddress);
                                
                                if ($googleCoords !== null) {
                                    $newMarker = $googleCoords['lat'] . ',' . $googleCoords['lon'];
                                    echo "    Google geocoding successful: {$newMarker}\n";
                                } else {
                                    echo "    Both geocoding APIs failed. Skipping service.\n";
                                    $servicesSkipped++;
                                    $failedServices[] = [
                                        'customer_name' => $customer['name'] ?? 'N/A',
                                        'service_id' => $service['id'] ?? 'N/A',
                                        'address' => $geoAddress,
                                        'reason' => 'Geocoding failed'
                                    ];
                                    continue;
                                }
                            }
                            
                            // Update only the marker field
                            $updateEndpoint = 'admin/customers/customer/' . $customer['id'] . '/geo-internet-service--' . $service['id'];
                            $geoUpdateData = ['marker' => $newMarker];
                            
                            $updateSuccess = $splynx->put($updateEndpoint, $geoUpdateData);
                            
                            if ($updateSuccess) {
                                echo "    Successfully updated geo.marker\n";
                                $servicesUpdated++;
                                $updatedServices[] = [
                                    'customer_name' => $customer['name'] ?? 'N/A',
                                    'service_id' => $service['id'] ?? 'N/A',
                                    'address' => $geoAddress,
                                    'coordinates' => $newMarker
                                ];
                            } else {
                                echo "    Failed to update geo.marker\n";
                                $failedServices[] = [
                                    'customer_name' => $customer['name'] ?? 'N/A',
                                    'service_id' => $service['id'] ?? 'N/A',
                                    'address' => $geoAddress,
                                    'reason' => 'API update failed'
                                ];
                            }
                        }
                    }
                    
                    if ($customerHasServicesNeedingGeocode) {
                        echo "-------------------------------------------\n";
                    }
                }
            }
        }
        
        echo "\n=== Summary ===\n";
        echo "Total active services processed: {$servicesProcessed}\n";
        echo "Services updated with coordinates: {$servicesUpdated}\n";
        echo "Services skipped: {$servicesSkipped}\n\n";
        
        if (!empty($updatedServices)) {
            echo "=== Services Successfully Updated ===\n";
            foreach ($updatedServices as $service) {
                echo "Customer: {$service['customer_name']}\n";
                echo "  Service ID: {$service['service_id']}\n";
                echo "  Address: {$service['address']}\n";
                echo "  Coordinates: {$service['coordinates']}\n";
                echo "---\n";
            }
        }
        
        if (!empty($failedServices)) {
            echo "\n=== Services That Could Not Be Updated ===\n";
            foreach ($failedServices as $service) {
                echo "Customer: {$service['customer_name']}\n";
                echo "  Service ID: {$service['service_id']}\n";
                echo "  Address: " . ($service['address'] ?? 'N/A') . "\n";
                echo "  Reason: {$service['reason']}\n";
                echo "---\n";
            }
        }
        
    } else {
        echo "No active customers found.\n";
    }
} else {
    echo "Failed to retrieve customer data. Please check your API Key, Secret, and Splynx API URL.\n";
}

?>
