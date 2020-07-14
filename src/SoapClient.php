<?php

namespace Etrias\CopernicaSoapClient;

class SoapClient extends \SoapClient
{
    const PARAM_ACCESS_TOKEN = 'access_token';

    /**
     *  The charset of the supplied parameters, and the returned data
     *  @var string Charset
     */
    private $charset;

    /**
     *  The login name
     *  @var string
     */
    protected $accessToken;

    /**
     *  The WSDL url
     *  @var string
     */
    protected $url;

    /**
     *  Overridden constructor
     *  @param  string  Access token
     *  @param  string  URL of the application
     *  @param  string  The charset that is used by the user of this class (this class takes care of converting it to utf-8 before it is sent to the api)
     */
    public function __construct($accessToken, $url = 'http://soap.copernica.com/', $charset = 'iso-8859-1')
    {
        // Store the data
        $this->accessToken = $accessToken;

        // store charset
        $this->charset = strtolower($charset);

        // Check the php version to determine the http to use
        if(version_compare(phpversion(), '5.3.0') >= 0)
        {
            $version = 1.1;
        }
        else
        {
            $version = 1.0;
        }

        // create default http context (required for decoding chunks). Since version 5.3 php supports chunked encoding.
        // The http version is set depending on the php version
        $context = stream_context_create(array(
            'http'  =>  array(
                'protocol_version'  =>  $version,
            ),
        ));

        // parameters for the SOAP connection
        $params = array(
            'soap_version'      =>  SOAP_1_1,
            'trace'             =>  true,
            'stream_context'    =>  $context,
            'cache_wsdl'        =>  WSDL_CACHE_BOTH
        );

        // Add compression if we're use http version 1.1
        if($version == 1.1)
        {
            $params['compression'] = SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP;
        }

        // url of the wsdl
        $this->url = $url."?SOAPAPI=WSDL";

        // create connection
        parent::__construct($this->url, $params);
    }

    /**
     *  Helper method to convert a string before it is sent to the api
     *  @param  string  text to convert
     *  @return string  converted text
     */
    private function convertToApi($text)
    {
        if ($this->charset == 'utf-8') return $text;
        return iconv($this->charset, "utf-8//TRANSLIT", $text);
    }

    /**
     *  Helper method to convert a string that is received from the api
     *  @param  string  text to convert
     *  @return string  converted text
     */
    private function convertFromApi($text)
    {
        if ($this->charset == 'utf-8') return $text;
        return iconv('utf-8', $this->charset."//TRANSLIT", $text);
    }

    /**
     *  Method that handles the calls to the API
     *  @param  string  Name of the method
     *  @param  array   Associative array of parameters
     *  @return mixed
     */
    public function __call($methodname, $params)
    {
        // one parameter is required
        $params = count($params) == 0 ? array() : $params[0];

        // check if the first param was an array
        if (!is_array($params)) trigger_error("Invalid parameters, array is required");

        // convert the parameters
        foreach ($params as $key => $value)
        {
            // check the type of the value, and do some conversions
            if ($this->isAssoc($value)) $params[$key] = $this->encodeAssoc($value);
            elseif (is_array($value)) $params[$key] = $this->encodeArray($value);
            elseif (is_object($value)) $params[$key] = $this->encodeObject($value);
            else $params[$key] = $this->convertToApi($value);
        }

        if (!isset($params[self::PARAM_ACCESS_TOKEN])) {
            $params[self::PARAM_ACCESS_TOKEN] = $this->accessToken;
        }

        // convert the parameters to an object
        $params = $this->toObject($params);

        // Make the call
        $result = parent::__call($methodname, array($params));

        // return the decoded result
        return $this->decodeResult($result);
    }

    /**
     *  Helper method that converts the result
     *  @param  object with the result
     *  @return mixed
     */
    private function decodeResult($result)
    {
        // is this an array result?
        if (isset($result->array))
        {
            // check if there are items
            if (!isset($result->array->item)) return array();

            // get the items, and make sure they are an array
            $items = $result->array->item;
            return is_array($items) ? array_map(array($this, 'convertFromApi'), $items) : array($this->convertFromApi($items));
        }

        // is this an assoc result
        if (isset($result->map))
        {
            // check if there are pairs
            if (!isset($result->map->pair)) return array();

            // get the pairs and make sure they are an array
            $pairs = $result->map->pair;
            if (!is_array($pairs)) $pairs = array($pairs);

            // loop through the pairs and convert them to an array
            $result = array();
            foreach ($pairs as $pair) $result[$this->convertFromApi($pair->key)] = $this->convertFromApi($pair->value);
            return $result;
        }

        // is this a collection?
        if (isset($result->start) && isset($result->length) && isset($result->total) && isset($result->items))
        {
            // empty array
            $items = array();

            // what is the name of the collection?
            $vars = array_keys(get_object_vars($result->items));
            foreach (array_unique($vars) as $membername)
            {
                // get the members
                $members = isset($result->items->$membername) ? $result->items->$membername : array();
                if (!is_array($members)) $members = array($members);

                // loop through the members
                foreach ($members as $member)
                {
                    // replace the items
                    $items[] = $this->decodeObject($member);
                }
            }

            // done
            $result->items = $items;
            return $result;
        }

        // is this a regular, scalar, result?
        if (isset($result->value)) return is_string($result->value) ? $this->convertFromApi($result->value) : $result->value;

        // finally, we assume this is an entity
        $vars = array_keys(get_object_vars($result));
        if (count($vars) == 0) return false;
        $membername = $vars[0];

        // return just the member
        return $this->decodeObject($result->$membername);
    }

    /**
     *  Encode an associative array to be used as parameter. Assoc arrays are
     *  represented as Map complex type in our SOAP API. Map type is nothing else
     *  than a sequence (PHPs array) of pairs (PHPs assoc or PHPs object).
     *
     *  @param  associative array
     *  @return array
     */
    private function encodeAssoc($array)
    {
        // we are going to construct an array of pairs
        $pairs = array();

        // loop through all keys and values in the array
        foreach ($array as $key => $value)
        {
            // check if we have a proper key. We don't want anything else than
            // int numbers of strings as a key.
            if (!is_int($key) && !is_string($key))
            {
                trigger_error('Invalid parameter: Complex keys are not supported.');
                continue;
            }

            // check if we have a proper value.
            if (is_null($value))
            {
                trigger_error('Invalid parameter: NULL values are not supported.');
                continue;
            }

            // we do not support nested objects for parameters
            if (is_object($value))
            {
                trigger_error('Invalid parameter: Nested objects are not supported.');
                continue;
            }

            // we want to check if someone is trying to make a nested array inside our assoc
            if ($this->isAssoc($value))
            {
                trigger_error('Invalid parameter: Nested associative arrays are not supported');
            }

            // only when a string, it has to be encoded
            if (is_string($key)) $key = $this->convertToApi($key);
            if (is_string($value)) $value = $this->convertToApi($value);

            // create a pair
            $pairs[] = array('key' => $key, 'value' => $value);
        }

        // done
        return $pairs;
    }

    /**
     *  Encode a normal array to be used as parameter
     *  @param  Normal array
     *  @return array
     */
    private function encodeArray($array)
    {
        // the result array
        $result = array();

        // loop through the values
        foreach ($array as $value)
        {
            // array values should be objects
            if (is_object($value)) $result[] = $this->encodeObject($value);
            elseif (is_array($value)) trigger_error('Invalid parameter: arrays of objects are not supported');
            elseif (is_string($value)) $result[] = $this->convertToApi($value);
            else $result[] = $value;
        }

        // done
        return $result;
    }

    /**
     *  Encode an object to be used as parameter
     *  @param      object
     *  @return     object
     */
    private function encodeObject($object)
    {
        // result object
        $result = new \stdClass();

        // loop through the object vars
        foreach (get_object_vars($object) as $key => $value)
        {
            // check if we have a proper key. We don't want anything else than
            // int numbers of strings as a key.
            if (!is_int($key) && !is_string($key))
            {
                trigger_error('Invalid parameter: Complex keys are not supported.');
                continue;
            }

            // check if we have a proper value.
            if (is_null($value))
            {
                trigger_error('Invalid parameter: NULL values are not supported.');
                continue;
            }

            // we do not support nested objects for parameters
            if (is_object($value))
            {
                trigger_error('Invalid parameter: Nested objects are not supported.');
                continue;
            }

            // only when a string, it has to be encoded
            if (is_string($key)) $key = $this->convertToApi($key);
            if (is_string($value)) $value = $this->convertToApi($value);

            // check if we have an assoc array that can be converted to Map structure
            if ($this->isAssoc($value)) $value = $this->encodeAssoc($value);

            // add the var
            $result->$key = $value;
        }

        // done
        return $result;
    }

    /**
     *  Decode an object to be used as result
     *  @param      object
     *  @return     object
     */
    private function decodeObject($object)
    {
        // result object
        $result = new \stdClass();

        // loop through the object vars
        foreach (get_object_vars($object) as $key => $value)
        {
            // only when a string, it has to be decoded
            if (is_string($key)) $key = $this->convertFromApi($key);
            if (is_string($value)) $value = $this->convertFromApi($value);
            if (is_object($value)) $value = $this->decodeObject($value);

            // add the var
            $result->$key = $value;
        }

        // done
        return $result;
    }

    /**
     *  Helper function checks if an array is associative
     *  @param  array
     *  @return boolean
     */
    public function isAssoc($array)
    {
        if (!is_array($array)) return false;
        foreach (array_keys($array) as $k => $v)
        {
            if ($k !== $v) return true;
        }
        return false;
    }

    /**
     *  Helper function that maps an assoc array to an object
     *  @param  associative array
     *  @return object
     */
    public function toObject($array)
    {
        return (object) $array;
    }
}
