<?php

/*
BSD 3-Clause License

Copyright (c) 2017, Australian Film Television and Radio School
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

* Redistributions of source code must retain the above copyright notice, this
  list of conditions and the following disclaimer.

* Redistributions in binary form must reproduce the above copyright notice,
  this list of conditions and the following disclaimer in the documentation
  and/or other materials provided with the distribution.

* Neither the name of the copyright holder nor the names of its
  contributors may be used to endorse or promote products derived from
  this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

class Switcher
{
    public $vmixApiUrl = 'http://x.x.x.x:8088/api'; // vMix API path
    public $sampleCountPerSecond = 15; // How often we hit the API per second
    public $switchTimeMin = 2; // Minimum time between switches
    public $switchTimeMax = 5; // Maximum time between switches
    public $playoutInputNames = ['Studio_1_Program_3']; // Names of the inputs that pertain to the playout system (usually NexGen). Must have underscores where spaces are
    public $ignoreInputs = ['Service_1']; // Array of inputs to completely ignore
    public $playoutThreshold = '0.1'; // The volume the playout system needs to go above before we consider the playout system is playing a song and we go to wide
    public $wideCamera = 'Wide'; // Name of the camera doing the wide shot
    public $nowPlayingOverlay = 'NowPlaying'; // Name of the virtual input which shows our "Now Playing" information
    public $nowPlayingSongTitleArtist = ''; // Internal variable
    public $nowPlayingOverlayShownTime = 0; // Becomes time the overlay was put to air
    public $nowPlayingOverlayShowAgainTime = 0; // Becomes time since the overlay was switched off
    public $nowPlayingOverlayOnAirDuration = 30; // Seconds
    public $nowPlayingOverlayShownCount = 0; // Internal counter
    public $nowPlayingOverlayShowTimes = 2; // Maximum amount of times the same overlay can be shown

    // Map the vmix microphone input names to the vmix camera names (Use underscores to substitute spaces)
    public $micCameraMap =
        [
            'Guest_Mic_1' => 'Guest_1_and_2',
            'Guest_Mic_2' => 'Guest_1_and_2',
            'Guest_Mic_3' => 'Guest_3',
            'Announcer_Mic' => 'Announcer',
            //'Studio_1_Program_4' => 'Studio_2_News',
            'Studio_1_Program_3' => 'Wide'
        ];

    public $userAgent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13'; // User agent we use when we present to the API

    function debugUtils()
    {
        function pr($debug)
        {
            var_dump($debug);
        }

        function prd($debug)
        {
            var_dump($debug);
            die();
        }
    }

    /**
     * The orchestrator, runs the main event loop
     */
    public function main()
    {
        // Set now playing overlay time to current time
        $this->nowPlayingOverlayShownTime = time();

        // Create our master loop
        while(true) {

            // Keep track of whether we have done a switch
            $switched = false;

            // Set a random time for the next switch to occur
            $switchDelayTime = rand($this->switchTimeMin,$this->switchTimeMax);

            // Get the state of the cameras
            $videoInput = $this->getVideoState();

            // Grab our volume state
            $audioInput = $this->getVolumeState();

            // Get the state of our overlays
            $titleInput = $this->getTitleState();

            $onAirOverlays = $this->getOnAirOverlays();

            // Remove ignored inputs
            foreach($this->ignoreInputs as $inputIgnore) {
                unset($audioInput[$inputIgnore]);
            }

            // Check if nexgen is above the threshold and if it is, cut to the wide shot.
            foreach($this->playoutInputNames as $inValue) {
                if($audioInput[$inValue]['volume'] > $this->playoutThreshold) {
                    $switched = true;
                    echo " Switching to wide \r \n";
                    $this->switchApiInput('cut', $videoInput[$this->wideCamera]['key']);

                    // Run now playing function to determine if we should show 'Now Playing Title'
                    $this->nowPlaying($titleInput, $onAirOverlays);
                }
            }

            // Nexgen isn't playing, so now find the microphone that is exceeding our threshold and cut to it
            if($switched == false) {
                $loudest = array_reduce($audioInput, function($a, $b) {
                    return $a['volume'] > $b['volume'] ? $a : $b;
                });

                if($this->micCameraMap[$loudest['name']] != NULL) {
                    echo " Switching to ".$loudest['name']."\r \n";
                    $this->switchApiInput('cut', $videoInput[$this->micCameraMap[$loudest['name']]]['key']);

                    // Show the now playing title if it is still shown
                    $this->nowPlaying($titleInput, $onAirOverlays, true);
                }
            }

            // Sleep for duration of the switch
            sleep($switchDelayTime);

            // If we are in the browser we only execute one loop, to avoid an endless loop
            if (!php_sapi_name() == "cli") {
                break;
            }
        }
    }

    private function nowPlaying($titleInput, $onAirOverlays, $hide = false)
    {
        // Make sure this overlay even exists
        if(isset($titleInput[$this->nowPlayingOverlay])) {

            echo " Now Playing Overlay Shown Time = ".$this->nowPlayingOverlayShownTime." \r \n";
            echo " Now Playing Overlay Show Again Time = ".$this->nowPlayingOverlayShowAgainTime." \r \n";
            echo " Now Playing Overlay On Air Duration = ".$this->nowPlayingOverlayOnAirDuration." \r \n";
            echo " Now Playing Overlay Count = ".$this->nowPlayingOverlayShownCount." \r \n";
            echo " Time = ".time()." \r \n";

            $overlayKey = $titleInput[$this->nowPlayingOverlay]['key'];

            // Check if we need to immediately hide the overlay if requested by external param
            if(!$hide) {

                // Check that the title isn't already on air
                if (isset($onAirOverlays[$titleInput[$this->nowPlayingOverlay]['number']])) {

                    // Overlay is On Air so make sure we haven't been on air longer than the set duration, else remove it
                    if ($this->nowPlayingOverlayShownTime + $this->nowPlayingOverlayOnAirDuration < time()) {

                        //echo " Overlay has been on air for ".$this->nowPlayingOverlayOnAirDuration.". Hiding Overlay \r \n";
                        // Remove Overlay
                        $this->hideNowPlaying($overlayKey);
                    }

                } else {

                    // We aren't on air so check if enough time has elapsed since the last overlay was hidden to see if we can show it again
                    if($this->nowPlayingOverlayShowAgainTime < time()) {

                        // Assemble the current song/artist into local var
                        $nowPlayingSongTitleArtist = (string)$titleInput[$this->nowPlayingOverlay]['textData'][0] . '_' . $titleInput[$this->nowPlayingOverlay]['textData'][1];

                        echo " Global Now Playing Title Artist = ".$this->nowPlayingSongTitleArtist." \r \n";
                        echo " Local Now Playing Title Artist = ".$nowPlayingSongTitleArtist." \r \n";

                        // Check the current artist + song title against the globally stored song title & artist
                        // If its different we overlay it after 3 seconds as we've never shown it before
                        if ($nowPlayingSongTitleArtist != $this->nowPlayingSongTitleArtist) {

                            echo " SONG IS DIFFERENT --- RESET Count \r \n";

                            // Place Now Playing "On Air"
                            $this->showNowPlaying($overlayKey);

                            // Update the global title
                            $this->nowPlayingSongTitleArtist = $nowPlayingSongTitleArtist;

                            // Reset the shown count to 0
                            $this->nowPlayingOverlayShownCount = 0;

                        } else {
                            // Make sure we are under the maximum number of times we can show this title
                            if($this->nowPlayingOverlayShownCount < $this->nowPlayingOverlayShowTimes) {
                                // Place Now Playing "On Air"
                                $this->showNowPlaying($overlayKey);
                            }
                        }
                    }
                }
            } else {
                $this->hideNowPlaying($overlayKey);
            }
        }
    }

    private function showNowPlaying($titleInputKey)
    {
        echo " \r \n ****** Showing Now Playing Overlay ****** \r \n";
        $this->switchApiInput('OverlayInput1', $titleInputKey);

        // Set now playing overlay time to current time
        $this->nowPlayingOverlayShownTime = time();

        // Increment the global count
        $this->nowPlayingOverlayShownCount++;
    }

    private function hideNowPlaying($titleInputKey)
    {
        echo " \r \n ****** Hiding Now Playing Overlay ****** \r \n";
        $this->switchApiInput('OverlayInput1Out', $titleInputKey);

        // Set not on air timer to current time
        $this->nowPlayingOverlayShowAgainTime = (time() + $this->nowPlayingOverlayOnAirDuration);
    }

    /**
     * Retrieves the volume data of vmix audio inputs over a second and returns an array of the volume inputs in order of loudness
     *
     * @return array
     */
    private function getVolumeState()
    {
        $volumeArray = [];
        $returnArray = [];
        $sleepTime = round((1000 / $this->sampleCountPerSecond) * 1000); // Microseconds

        // Setup polling loop to get audio states from the audio inputs
        for ($x = 0; $x < $this->sampleCountPerSecond; $x++) {
            $xml = $this->get_xml_from_url();
            $audioInputs = $xml->xPath("/vmix/inputs/input[@type='Audio']");

            // Add each sample pass to a volume array
            foreach($audioInputs as $inputKey => $inputValue) {
                $volumeArray[$inputKey][$x]['name'] = $this->xml_attribute($inputValue, 'title');
                $volumeArray[$inputKey][$x]['key'] = $this->xml_attribute($inputValue, 'key');
                $volumeArray[$inputKey][$x]['volume_left'] = $this->xml_attribute($inputValue, 'meterF1');
                $volumeArray[$inputKey][$x]['volume_right'] = $this->xml_attribute($inputValue, 'meterF2');
            }

            // Sleep in microseconds
            usleep($sleepTime);
        }

        // Loop through input of the volume array
        foreach ($volumeArray as $inputKey => $inputVal) {
            $tempLeft = 0;
            $tempRight = 0;
            $name = str_replace(' ', '_', $inputVal[$inputKey]['name']);
            $returnArray[$name]['name'] = $name;
            $returnArray[$name]['key'] = $inputVal[$inputKey]['key'];

            foreach ($inputVal as $key => $value) {
                $tempLeft += $value['volume_left'];
                $tempRight += $value['volume_right'];
                $tempAverage = round((($tempLeft + $tempRight) / 2) / $this->sampleCountPerSecond, 2);
                $returnArray[$name]['volume'] = $tempAverage;
            }
        }

        return $returnArray;
    }

    /**
     * Gets the vmix inputs with type of 'Capture'
     *
     * @return array
     */
    private function getVideoState()
    {
        $returnArray = [];
        $xml = $this->get_xml_from_url();
        $videoInputs = $xml->xPath("/vmix/inputs/input[@type='Capture']");

        foreach($videoInputs as $inputKey => $inputValue) {
            $name = str_replace(' ', '_', $this->xml_attribute($inputValue, 'title'));
            $returnArray[$name]['key'] = $this->xml_attribute($inputValue, 'key');
        }

        return $returnArray;
    }

    /**
     * Gets the vmix inputs with type of 'Xaml'
     *
     * @return array
     */
    private function getTitleState()
    {
        $returnArray = [];
        $xml = $this->get_xml_from_url();
        $videoInputs = $xml->xPath("/vmix/inputs/input[@type='Xaml']");

        foreach($videoInputs as $inputKey => $inputValue) {
            $name = str_replace(' ', '_', $this->xml_attribute($inputValue, 'title'));
            $returnArray[$name]['key'] = $this->xml_attribute($inputValue, 'key');
            $returnArray[$name]['number'] = $this->xml_attribute($inputValue, 'number');
            $returnArray[$name]['textData'] = $this->xml_attribute($inputValue, 'text');
        }

        return $returnArray;
    }

    /**
     * Returns an array of input numbers that are currently "onair"
     *
     * @return array
     */
    private function getOnAirOverlays()
    {
        $returnArray = [];
        $xml = $this->get_xml_from_url();
        $overlays = $xml->xPath("/vmix/overlays");

        foreach($overlays[0] as $overlay) {
            if(strlen($overlay) > 0) {
                $returnArray[] = (int) $overlay;
            }
        }

        // Flip array so the values become searchable keys
        return array_flip($returnArray);
    }

    /**
     * Performs the actual call to switch the input on the vMix API
     *
     * @param $action
     * @param $key
     * @return mixed
     */
    private function switchApiInput($action, $key)
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $this->vmixApiUrl.'?Function='.$action.'&Input='.$key,
            CURLOPT_USERAGENT => $this->userAgent
        ]);

        $response = curl_exec($ch);

        curl_close($ch);

        return $response;
    }

    /**
     * Retrieves XML from URL
     *
     * @return SimpleXMLElement
     */
    private function get_xml_from_url()
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->vmixApiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);

        $xmlstr = curl_exec($ch);
        curl_close($ch);

        return simplexml_load_string($xmlstr);
    }

    /**
     * Transforms object values to string
     *
     * @param $object
     * @param $attribute
     * @return string
     */
    private function xml_attribute($object, $attribute)
    {
        if (isset($object[$attribute])) {
            return (string)$object[$attribute];
        } elseif (isset($object->$attribute)) {
            return $object->$attribute;
        }
    }

    /**
     * Check if ANY of the needles exist (http://stackoverflow.com/a/11040612)
     *
     * @param $needles
     * @param $haystack
     * @return bool
     */
    private function in_array_any($needles, $haystack)
    {
        return !!array_intersect($needles, $haystack);
    }
}

$switcher = new Switcher();
$switcher->debugUtils();
$switcher->main();


