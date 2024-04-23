<?php

namespace FreePBX\modules\Core\Restore;

class Devices extends Corebase{
	private $backupinfo;
	public function setbackupinfo($backupinfo){

		$this->backupinfo = $backupinfo;
		return $this;
	}

	public function setConfigs($configs){
		if(count($configs) > 0){
			$this->updateDevices($configs);
		}
		//method to convet the chansip extensions to pjsip
		if(isset($this->backupinfo['convertchansipexts']) && $this->backupinfo['convertchansipexts']) {
			$this->FreePBX->Core->convert2pjsip();
		}
		//method to convet the chansip extensions
		if(isset($this->backupinfo['skipchansipexts']) && $this->backupinfo['skipchansipexts']) {
			$this->FreePBX->Core->skipchansip();
		}
		$this->FreePBX->Core->devices2astdb();
		return $this;
	}

	private function updateDevices($devices) {
		$sth = $this->FreePBX->Database->prepare("INSERT INTO devices (`id`, `tech`, `dial`, `devicetype`, `user`, `description`, `emergency_cid`, `hint_override`) VALUES (:id, :tech, :dial, :devicetype, :user, :description, :emergency_cid, :hint_override)");
		foreach($devices['devices'] as $device) {
			$sth->execute($device);
		}
		foreach($devices['techTables'] as $tech => $rows) {
			$sth = $this->FreePBX->Database->prepare("INSERT INTO $tech (`id`, `keyword`, `data`, `flags`) VALUES (:id, :keyword, :data, :flags)");
			foreach($rows as $row) {
				$sth->execute($row);
			}
		}
	}
}
