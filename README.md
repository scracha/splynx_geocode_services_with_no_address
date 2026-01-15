Iterates through active customers' active internet services and geocodes them via Nominatim and if that fails, via Google Geocoding APi.
Where a service doesn't have an address, it will assign the service the customer's address prior to geocoding.

Optional limit to country code(s) listed in the config file.
