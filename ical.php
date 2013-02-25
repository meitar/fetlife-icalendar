<?php
require_once 'lib/FetLife/FetLife.php';
require_once 'lib/iCalcreator/iCalcreator.class.php';

// Load configuration data.
$flical_config = parse_ini_file('./fetlife-icalendar.ini.php', true);
if (!$flical_config) {
    die("Failed to load configuration file.");
}

$FL = new FetLifeUser($flical_config['FetLife']['username'], $flical_config['FetLife']['password']);
if ($flical_config['FetLife']['proxyurl']) {
    $p = parse_url($flical_config['FetLife']['proxyurl']);
    $FL->connection->setProxy(
        "{$p['host']}:{$p['port']}",
        ('socks' === $p['scheme']) ? CURLPROXY_SOCKS5 : CURLPROXY_HTTP
    );
}
$FL->logIn() or die("Failed to log in to FetLife.");

// All your events are belong to us.
$places = $flical_config; // Copy the config array.
while ($place = array_splice($places, 0, 1)) {
    if ($place['FetLife']) { continue; }
    // Get key of first item, which is the place name.
    reset($place);
    $k = key($place);
    // Bail if we're missing any crucial parameters.
    if (!$place[$k]['placeurl'] || !$place[$k]['timezone']) {
        continue;
    }

    // Set export options for this place.
    $num_pages = ($place[$k]['pages']) ? $place[$k]['pages'] : $flical_config['FetLife']['pages'];
    switch ($num_rsvps = strtolower(
        ($place[$k]['rsvps']) ? $place[$k]['rsvps'] : $flical_config['FetLife']['rsvps']
    )) {
        case 'all':
            $populate = true;
            break;
        case 'none':
            $populate = false;
            break;
        default:
            $populate = (int) $num_rsvps;
            break;
    }
    switch ($summaries = strtolower(
        ($place[$k]['summaries']) ? $place[$k]['summaries'] : $flical_config['FetLife']['summaries']
    )) {
        case 'on':
            $summaries = true;
            break;
        case 'off':
        default:
            $summaries = false;
            break;
    }

    // Deal with timezone headaches.
    date_default_timezone_set($place[$k]['timezone']);

    // Get an iCalcreator instance.
    $vcal = new vcalendar();

    // Configure it.
    $vcal->setConfig('unique_id', 'fetlife-icalendar-' . $_SERVER['SERVER_NAME']);
    $vcal->setConfig('TZID', $place[$k]['timezone']);

    // Set calendar properties.
    $vcal->setProperty('method', 'PUBLISH');
    $vcal->setProperty('x-wr-calname', "$k FetLife Events");
    $vcal->setProperty('X-WR-TIMEZONE', $place[$k]['timezone']);
    $vcal->setProperty('X-WR-CALDESC', "FetLife Events in $k. via https://fetlife.com/{$place[$k]['placeurl']}/events");

    $x = $FL->getUpcomingEventsInLocation($place[$k]['placeurl'], $num_pages);
    foreach ($x as $event) {
        // If the "summaries" option is set, don't populate any event data.
        if (!$summaries) {
            $event->populate($populate);
        }
        $vevent = &$vcal->newComponent('vevent');
        // FetLife doesn't actually provide UTC time, even though it claims to. :(
        $vevent->setProperty('dtstart', substr($event->dtstart, 0, -1));
        if ($event->dtend) {
            $vevent->setProperty('dtend', substr($event->dtend, 0, -1));
        }
        // TODO: Add an appropriate URI representation for LOCATION.
        //       See: http://www.kanzaki.com/docs/ical/location.html#example
        $vevent->setProperty('LOCATION', trim($event->venue_name) . ' @ ' . trim($event->venue_address));
        $vevent->setProperty('summary', $event->title);
        $desc = trim($event->description);
        $desc .= ($event->cost) ? "\n\nCost: {$event->cost}" : '';
        $desc .= ($event->dress_code) ? "\n\nDress code: {$event->dress_code}" : '';
        $vevent->setProperty('description', $desc);
        $vevent->setProperty('url', $event->getPermalink());
        if ($event->created_by) {
            $vevent->setProperty('organizer', $event->created_by->getPermalink(), array(
                'CN' => $event->created_by->nickname
            ));
        }
        if ($event->going) {
            foreach ($event->going as $profile) {
                $vevent->setProperty('attendee', $profile->getPermalink(), array(
                    'role' => 'OPT-PARTICIPANT',
                    'PARTSTAT' => 'ACCEPTED',
                    'CN' => $profile->nickname
                ));
            }
        }
        if ($event->maybegoing) {
            foreach ($event->maybegoing as $profile) {
                $vevent->setProperty('attendee', $profile->getPermalink(), array(
                    'role' => 'OPT-PARTICIPANT',
                    'PARTSTAT' => 'TENTATIVE',
                    'CN' => $profile->nickname
                ));
            }
        }
    }

    // Set timezone offsets.
    iCalUtilityFunctions::createTimezone($vcal, $place[$k]['timezone']);

    // Finally, print the file.
    // TODO: Create an output directory option.
    //$vcal->setConfig('directory', 'ical');
    $vcal->setConfig('filename', str_replace(' ', '_', key($place)) . '.ics');
    $vcal->saveCalendar();
}
?>
