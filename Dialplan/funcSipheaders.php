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
		$ext->add($c,$e,'', new \ext_set('DIALLEGEXT','${IF($[${LEN(${DialMCEXT})}>0]?${DialMCEXT}:${CUT(${Dchan},-,1)})}'));
		$ext->add($c,$e,'', new \ext_set('RG_VOLHDRKEY', 'Alert-Info-Ring-Volume'));
		// Per-leg P/S RVOL only for mixed ringall (one Dial, shared headers). Queues use macro-dial-one per agent.
		$ext->add($c,$e,'', new \ext_set('RG_PERLEG', '${RG_SHARED_RVOL}'));
		$ext->add($c,$e,'', new \ext_gotoif('$["${RG_PERLEG}" = "0"]', 'headers'));
		$ext->add($c,$e,'', new \ext_gosub('1','s','sub-check-pseries','${DIALLEGEXT}'));
		$ext->add($c,$e,'headers', new \ext_set('TECH', '${CUT(CHANNEL,/,1)}'));
		// Prefer inherited __SIPHEADERS from the caller; fall back to SIPHEADERS when present.
		$ext->add($c,$e,'', new \ext_set('SIPHEADERKEYS', '${IF($[${LEN(${HASHKEYS(__SIPHEADERS)})}>0]?${HASHKEYS(__SIPHEADERS)}:${HASHKEYS(SIPHEADERS)})}'));
		$ext->add($c,$e,'', new \ext_while('$["${SET(sipkey=${SHIFT(SIPHEADERKEYS)})}" != ""]'));
		$ext->add($c,$e,'', new \ext_set('sipheader', '${IF($[${LEN(${HASH(__SIPHEADERS,${sipkey})})}>0]?${HASH(__SIPHEADERS,${sipkey})}:${HASH(SIPHEADERS,${sipkey})})}'));
		// Ringall shares one header set: P-phones use Alert-Info-Ring-Volume; S/D use Alert-Info;volume=
		$ext->add($c,$e,'', new \ext_gotoif('$["${RG_PERLEG}" = "0"]', 'applyheader'));
		$ext->add($c,$e,'', new \ext_gotoif('$["${sipkey}" = "${RG_VOLHDRKEY}" & "${IS_PPHONE}" != "1"]', 'nextheader'));
		$ext->add($c,$e,'', new \ext_gotoif('$["${sipkey}" != "Alert-Info" | "${IS_PPHONE}" = "1"]', 'applyheader'));
		$ext->add($c,$e,'', new \ext_gotoif('$[${REGEX("volume=" ${sipheader})} = 1]', 'applyheader'));
		$ext->add($c,$e,'', new \ext_set('RVOL_USE', '${IF($["${RVOL}"!=""]?${RVOL}:${RVOLPARENT})}'));
		$ext->add($c,$e,'', new \ext_gotoif('$["${RVOL_USE}" = ""]', 'applyheader'));
		$ext->add($c,$e,'', new \ext_set('RG_AITONE', '${IF($["${ALERT_INFO}"!=""]?${ALERT_INFO}:Normal)}'));
		$ext->add($c,$e,'', new \ext_set('sipheader', '${RG_AITONE}\;volume=${RVOL_USE}'));
		$ext->add($c,$e,'applyheader', new \ext_noop(''));
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
		$ext->add($c,$e,'nextheader', new \ext_endwhile(''));
		$ext->add($c,$e,'', new \ext_return());

		self::addPseriesCheck($ext);
		self::addRvolHeaders($ext);
	}

	/**
	 * Apply Alert-Info / Alert-Info-Ring-Volume for RVOL (queues, ring groups, hunt, direct dial).
	 * ARG1: ringall = use IS_PPHONE_ANY; single = use IS_PPHONE per EXTTOCALL.
	 */
	static function addRvolHeaders($ext) {
		$c = 'sub-set-rvol-headers';
		$s = 's';

		$ext->add($c, $s, '', new \ext_gotoif('$["${ARG1}" = "ringall"]', 'ringall_p'));
		$ext->add($c, $s, '', new \ext_execif('$["${IS_PPHONE}"="1" & "${RVOL}"!=""]', 'Set', 'HASH(__SIPHEADERS,Alert-Info-Ring-Volume)=${RVOL}'));
		$ext->add($c, $s, '', new \ext_execif('$["${IS_PPHONE}"="1" & "${RVOL}"="" & "${DB(AMPUSER/${EXTTOCALL}/rvolume)}" != ""]', 'Set', 'HASH(__SIPHEADERS,Alert-Info-Ring-Volume)=${DB(AMPUSER/${EXTTOCALL}/rvolume)}'));
		$ext->add($c, $s, '', new \ext_goto('common'));

		$ext->add($c, $s, 'ringall_p', new \ext_execif('$["${IS_PPHONE_ANY}"="1" & "${RVOL}"!=""]', 'Set', 'HASH(__SIPHEADERS,Alert-Info-Ring-Volume)=${RVOL}'));
		$ext->add($c, $s, '', new \ext_goto('common'));

		// S/D-series (and mixed ringall): Alert-Info with ;volume=, same as queues
		$ext->add($c, $s, 'common', new \ext_gotoif('$["${RVOL}"=""]', 'no_rvol'));
		$ext->add($c, $s, '', new \ext_set('RVOL_AI', '${IF($["${ALERT_INFO}"!=""]?${ALERT_INFO}:Normal)}'));
		$ext->add($c, $s, '', new \ext_set('HASH(__SIPHEADERS,Alert-Info)', '${RVOL_AI}\;volume=${RVOL}'));
		$ext->add($c, $s, '', new \ext_goto('done'));
		$ext->add($c, $s, 'no_rvol', new \ext_execif('$["${ALERT_INFO}"!=""]', 'Set', 'HASH(__SIPHEADERS,Alert-Info)=${ALERT_INFO}'));
		$ext->add($c, $s, '', new \ext_gotoif('$["${ARG1}" = "ringall"]', 'done'));
		$ext->add($c, $s, '', new \ext_execif('$["${RVOL}"="" & "${DB(AMPUSER/${EXTTOCALL}/rvolume)}" != ""]', 'Set', 'HASH(__SIPHEADERS,Alert-Info)=${IF($["${ALERT_INFO}"!=""]?${ALERT_INFO}:Normal)}\;volume=${DB(AMPUSER/${EXTTOCALL}/rvolume)}'));
		$ext->add($c, $s, '', new \ext_execif('$["${IS_PPHONE}"="1" & "${RVOL}"="" & "${DB(AMPUSER/${EXTTOCALL}/rvolume)}" != ""]', 'Set', 'HASH(__SIPHEADERS,Alert-Info)=${IF($["${ALERT_INFO}"!=""]?${ALERT_INFO}:Normal)}'));

		$ext->add($c, $s, 'done', new \ext_gosubif('$["${ALERT_INFO}"!="" & "${ALERT_INFO}"!=" " & "${HASH(__SIPHEADERS,Alert-Info)}"=""]', 'func-set-sipheader,s,1', false, 'Alert-Info,${ALERT_INFO}'));
		$ext->add($c, $s, '', new \ext_return());
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

		$ext->add($c, $s, 'sipua', new \ext_gotoif('$["${EXTENSION_STATE(${ARG1}@ext-local)}" = "UNAVAILABLE" | "${EXTENSION_STATE(${ARG1}@ext-local)}" = "UNKNOWN"]', 'done'));
		$ext->add($c, $s, '', new \ext_set('USERAGENT', '${SIPPEER(${CUT(DEVICE,/,2)},useragent)}'));
		$ext->add($c, $s, '', new \ext_goto('uacheck'));

		$ext->add($c, $s, 'pjsipua', new \ext_set('AOR', '${CUT(DEVICE,/,2)}'));
		$ext->add($c, $s, '', new \ext_gotoif('$[${LEN(${PJSIP_DIAL_CONTACTS(${ARG1})})}=0]', 'done'));
		$ext->add($c, $s, '', new \ext_set('CONTACT', '${PJSIP_AOR(${AOR},contact)}'));
		$ext->add($c, $s, '', new \ext_gotoif('$["${CONTACT}" = ""]', 'done'));
		$ext->add($c, $s, '', new \ext_set('USERAGENT', '${PJSIP_CONTACT(${CONTACT},user_agent)}'));
		$ext->add($c, $s, '', new \ext_goto('uacheck'));

		$ext->add($c, $s, 'uacheck', new \ext_execif('$["${USERAGENT:0:9}" = "Sangoma P"]', 'Set', 'IS_PPHONE=1'));
		$ext->add($c, $s, '', new \ext_goto('done'));
	}
}
