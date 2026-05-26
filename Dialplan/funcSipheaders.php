<?php
namespace FreePBX\modules\Core\Dialplan;

class funcSipheaders{
	static function add($ext){
		/*
		* Set a SIP Header to be used in the next call.
		*/

		$c = 'func-set-sipheader'; // Context
		$e = 's'; // Exten

		$ext->add($c,$e,'', new \ext_noop('Sip Add Header function called. Adding ${ARG1} = ${ARG2}'));
		$ext->add($c,$e,'', new \ext_set('HASH(__SIPHEADERS,${ARG1})', '${ARG2}'));
		$ext->add($c,$e,'', new \ext_return());

		/*
		* Apply a SIP Header to the call that's about to be made
		*/

		$c = 'func-apply-sipheaders';

		$ext->add($c,$e,'', new \ext_noop('Applying SIP Headers to channel ${CHANNEL}'));
		$ext->add($c,$e,'', new \ext_set('Dchan','${CUT(CHANNEL,/,2)}'));
		$ext->add($c,$e,'', new \ext_set('TECH', '${CUT(CHANNEL,/,1)}'));
		// Prefer inherited __SIPHEADERS from the caller; fall back to SIPHEADERS when present.
		$ext->add($c,$e,'', new \ext_set('SIPHEADERKEYS', '${IF($[${LEN(${HASHKEYS(__SIPHEADERS)})}>0]?${HASHKEYS(__SIPHEADERS)}:${HASHKEYS(SIPHEADERS)})}'));
		$ext->add($c,$e,'', new \ext_while('$["${SET(sipkey=${SHIFT(SIPHEADERKEYS)})}" != ""]'));
		$ext->add($c,$e,'', new \ext_set('sipheader', '${IF($[${LEN(${HASH(__SIPHEADERS,${sipkey})})}>0]?${HASH(__SIPHEADERS,${sipkey})}:${HASH(SIPHEADERS,${sipkey})})}'));
		$driver = \FreePBX::Config()->get("ASTSIPDRIVER");
		if (in_array($driver,array("both","chan_sip"))) {
			$ext->add($c,$e,'', new \ext_execif('$["${sipheader}" = "unset" & "${TECH}" = "SIP"]','SIPRemoveHeader','${sipkey}:'));
		}
		if (in_array($driver,array("both","chan_pjsip"))) {
			$ext->add($c,$e,'', new \ext_execif('$["${sipheader}" = "unset" & "${TECH}" = "PJSIP"]','Set','PJSIP_HEADER(remove,${sipkey})='));
		}

		if(\FreePBX::Config()->get('RFC7462')) {
			$ext->add($c,$e,'', new \ext_execif('$["${sipheader}" != "unset" & "${sipkey}" = "Alert-Info" & ${REGEX("^<[^>]*>" ${sipheader})} != 1 & ${REGEX("\;info=" ${sipheader})} != 1]', 'Set', 'sipheader=<http://127.0.0.1>\;info=${sipheader}'));
			$ext->add($c,$e,'', new \ext_execif('$["${sipheader}" != "unset" & "${sipkey}" = "Alert-Info" & ${REGEX("^<[^>]*>" ${sipheader})} != 1]', 'Set', 'sipheader=<http://127.0.0.1>${sipheader}'));
		}

		if(in_array($driver,array("both","chan_sip"))) {
			$ext->add($c,$e,'', new \ext_execif('$["${TECH}" = "SIP" & "${sipheader}" != "unset"]','SIPAddHeader','${sipkey}:${sipheader}'));
		}
		if(in_array($driver,array("both","chan_pjsip"))) {
			$ext->add($c,$e,'', new \ext_execif('$["${TECH}" = "PJSIP" & "${sipheader}" != "unset"]','Set','PJSIP_HEADER(add,${sipkey})=${sipheader}'));
		}
		$ext->add($c,$e,'', new \ext_endwhile(''));
		$ext->add($c,$e,'', new \ext_return());

		self::addPseriesCheck($ext);
	}

	/**
	 * Set IS_PPHONE=1 for Sangoma P-series (User-Agent prefix "Sangoma P", same as macro-autoanswer).
	 */
	static function addPseriesCheck($ext) {
		$c = 'sub-check-pseries';
		$s = 's';
		$ext->add($c, $s, '', new \ext_set('IS_PPHONE', '0'));
		$ext->add($c, $s, '', new \ext_set('DEVICE', '${DB(DEVICE/${ARG1}/dial)}'));
		$ext->add($c, $s, '', new \ext_gotoif('$["${DEVICE:0:5}" = "PJSIP"]', 'pjsipua'));
		$ext->add($c, $s, '', new \ext_gotoif('$["${DEVICE:0:3}" = "SIP"]', 'sipua'));
		$ext->add($c, $s, 'done', new \ext_return());

		$ext->add($c, $s, 'sipua', new \ext_set('USERAGENT', '${SIPPEER(${CUT(DEVICE,/,2)},useragent)}'));
		$ext->add($c, $s, '', new \ext_goto('uacheck'));

		$ext->add($c, $s, 'pjsipua', new \ext_set('AOR', '${CUT(DEVICE,/,2)}'));
		$ext->add($c, $s, '', new \ext_set('CONTACT', '${PJSIP_AOR(${AOR},contact)}'));
		$ext->add($c, $s, '', new \ext_set('USERAGENT', '${PJSIP_CONTACT(${CONTACT},user_agent)}'));

		$ext->add($c, $s, 'uacheck', new \ext_execif('$["${USERAGENT:0:9}" = "Sangoma P"]', 'Set', 'IS_PPHONE=1'));
		$ext->add($c, $s, '', new \ext_goto('done'));
	}
}
