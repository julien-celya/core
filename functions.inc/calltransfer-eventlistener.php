<?php

out("Call Trasnfer Event listener ");
$astman->Events("on");

$astman->add_event_handler("AttendedTransfer", function($event, $data, $server, $port) {
	core_AttendedTransfer($data);
});

$astman->add_event_handler("BlindTransfer", function($event, $data, $server, $port) {
	core_BlindTransfer($data);
});

$astman->add_event_handler("UnParkedCall", function($event, $data, $server, $port) {
	core_UnParkedCall($data,$event);
});
$last_db_ping = time();
while (true) {
	if (time() > ($last_db_ping + (60 * 60))) {
		$last_db_ping = time();
	}

	$response = $astman->wait_response(true);
	$reconnects = $astman->reconnects;

	while ($response === false && $reconnects > 0) {
		$astman->disconnect();
		if ($astman->connect($astman->server . ':' . $astman->port, $astman->username, $astman->secret, $astman->events) !== false) {
			$response = true;
		} else {
			if ($reconnects > 1) {
				$astman->log("reconnect command failed, sleeping before next attempt");
				sleep(1);
			} else {
				$astman->log("FATAL: no reconnect attempts left, command permanently failed");
				exit(2);
			}
		}
		$reconnects--;
	}
}

function core_UnParkedCall($data,$type){
	global $astman,$monitordir,$format;
	$ParkeeChannel = isset($data['ParkeeChannel']) ? $data['ParkeeChannel'] : '';
	if ($ParkeeChannel === '') {
		return;
	}
	$responseArray = core_channel_dump($ParkeeChannel);
	$filename = core_channel_var($responseArray, 'MIXMONITOR_FILENAME');
	// MIXMONITOR_FILENAME is already set because MixMonitor is still on this
	// channel. Parking does not stop it. Starting another MixMonitor here
	// appends a second writer to the same file (WAV/GSM recordings break).
	if ($filename != ""){
		dbug(" Skipping UnPark MixMonitor on $ParkeeChannel; already recording $filename");
		return ;
	}
	if (core_channel_has_mixmonitor($ParkeeChannel)) {
		dbug(" Skipping UnPark MixMonitor on $ParkeeChannel; MixMonitor already active");
		return;
	}
	$callfilename = core_channel_var($responseArray, 'CALLFILENAME');
	if($callfilename != ""){
		$filename = $monitordir.'/'.date("Y/m/d/").$callfilename.".".$format;
		core_start_mixmonitor($ParkeeChannel, $filename, 'UnPark');
	}
	return;
}

function core_event_val($data, $keys) {
	foreach ((array)$keys as $key) {
		if (isset($data[$key]) && $data[$key] !== '') {
			return $data[$key];
		}
		foreach ($data as $dk => $dv) {
			if (strcasecmp((string)$dk, (string)$key) === 0 && $dv !== '') {
				return $dv;
			}
		}
	}
	return '';
}

function core_AttendedTransfer($data) {
	core_transfer_continue_recording(
		core_event_val($data, array('OrigTransfererChannel', 'TransfererChannel')),
		core_event_val($data, array('TransfereeChannel')),
		'AttendedTransfer'
	);
}

function core_BlindTransfer($data) {
	core_transfer_continue_recording(
		core_event_val($data, array('TransfererChannel', 'OrigTransfererChannel')),
		core_event_val($data, array('TransfereeChannel')),
		'BlindTransfer'
	);
}

/**
 * Continue recording onto the remaining channel only when MixMonitor is gone.
 * Park is a BlindTransfer of the already-recorded channel; MixMonitor stays
 * on that channel, so this must not start a second writer on the same file.
 */
function core_transfer_continue_recording($fromChannel, $toChannel, $reason = 'Transfer') {
	if ($toChannel === '') {
		return;
	}
	if (core_channel_has_mixmonitor($toChannel) || core_channel_var(core_channel_dump($toChannel), 'MIXMONITOR_FILENAME') != '') {
		dbug(" Skipping $reason MixMonitor on $toChannel; already recording");
		return;
	}
	$filename = '';
	if ($fromChannel !== '') {
		$filename = core_channel_var(core_channel_dump($fromChannel), 'MIXMONITOR_FILENAME');
	}
	if ($filename == '') {
		$filename = core_channel_var(core_channel_dump($toChannel), 'MIXMONITOR_FILENAME');
	}
	if ($filename != '') {
		core_start_mixmonitor($toChannel, $filename, $reason);
	}
}

function core_channel_dump($channel) {
	global $astman;
	if ($channel === '') {
		return array();
	}
	$response = $astman->send_request('Command',array('Command'=>"core show channel ".$channel));
	return explode("\n", trim(isset($response['data']) ? $response['data'] : ''));
}

function core_channel_var($responseArray, $name) {
	$hits = preg_grep('/'.preg_quote($name, '/').'=/', $responseArray);
	if (!is_array($hits) || count($hits) === 0) {
		return '';
	}
	$hits = array_values($hits);
	$parts = explode($name.'=', $hits[0], 2);
	return isset($parts[1]) ? trim($parts[1]) : '';
}

function core_channel_has_mixmonitor($channel) {
	global $astman;
	if ($channel === '') {
		return false;
	}
	$response = $astman->send_request('Command',array('Command'=>"mixmonitor list ".$channel));
	$data = isset($response['data']) ? $response['data'] : '';
	if ($data === '' || preg_match('/No channel matching|No MixMonitor/i', $data)) {
		return false;
	}
	return (bool) preg_match('/\.\w{2,4}\b/', $data);
}

function core_start_mixmonitor($channel, $filename, $reason) {
	global $astman;
	if ($channel === '' || $filename === '') {
		return;
	}
	if (core_channel_has_mixmonitor($channel)) {
		dbug(" Skipping $reason MixMonitor on $channel; already recording $filename");
		return;
	}
	$astman->mixmonitor($channel, "$filename", "ai(LOCAL_MIXMON_ID)");
	dbug(" Starting $reason recording from Channel $channel with file $filename");
}
?>
