<?php

namespace FreePBX\modules\Core\Restore;

class Routing extends Corebase{
	public function setConfigs($configs){
		$routing = new \FreePBX\modules\Core\Components\Outboundrouting($this->FreePBX->Database);
		usort($configs, function ($a, $b) {
			return (int) $a['seq'] <=> (int) $b['seq'];
		});
		foreach ($configs as $route) {
			// get notif
			$emailfrom = $emailto =  $emailsubject =  $emailbody = '';
			if(isset($route['notification']) && is_array($route['notification'])) {
				$emailfrom    = $route['notification']['emailfrom'] ?? '';
				$emailto      = $route['notification']['emailto'] ?? '';
				$emailsubject = $route['notification']['emailsubject'] ?? '';
				$emailbody    = $route['notification']['emailbody'] ?? '';
			}
			$routing->editById($route['route_id'], $route['name'], $route['outcid'], $route['outcid_mode'], $route['password'], $route['emergency_route'], $route['intracompany_route'], $route['mohclass'], $route['time_group_id'], $route['patterns'], $route['trunks'], $route['seq'], $route['dest'], $route['time_mode'], $route['timezone'], $route['calendar_id'], $route['calendar_group_id'], $route['notification_on'], $emailfrom , $emailto, $emailsubject, $emailbody);
		}
		return $this;
	}
}
