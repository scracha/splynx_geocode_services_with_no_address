Iterates through active customers' active internet services and geocodes them via Nominatim and if that fails, via Google Geocoding APi.
Where a service doesn't have an address, it will assign the service the customer's address prior to geocoding.

Option to limit geocoding by country code(s) listed in the config file.


Suggested crontab:-

# Geocode services with no address daily at 5am
* 5 * * *  /usr/bin/php /root/splynx-php/geocode_services_with_no_address.php > /dev/null 2&1

