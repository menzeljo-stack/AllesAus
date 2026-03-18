<?php

class AllesAus extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceList', '[]');
        $this->RegisterPropertyInteger('PushoverInstance', 0);
        $this->RegisterPropertyString('PushTitle', 'ECO Manager');

        $this->RegisterVariableBoolean('State', 'Status', '~Switch', 0);
        $this->EnableAction('State');
        
        $this->SetBuffer('WatchList', json_encode([]));
        $this->SetBuffer('EcoTable', json_encode([])); // [VarID => OffTimestamp]

        // Timer für ECO-Prüfung (alle 60 Sekunden)
        $this->RegisterTimer('EcoTimer', 0, "ALOA_EcoTick(\$_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        $watchList = [];
        $hasEco = false;

        if (is_array($list)) {
            foreach ($list as $device) {
                if (!$device['Enabled'] || $device['DeviceID'] <= 0) continue;

                if (IPS_VariableExists($device['DeviceID'])) {
                    $this->RegisterMessage($device['DeviceID'], VM_UPDATE);
                    
                    if ($device['LedVarID1'] > 0) $watchList[$device['DeviceID']][] = $device['LedVarID1'];
                    if ($device['LedVarID2'] > 0) $watchList[$device['DeviceID']][] = $device['LedVarID2'];
                    
                    if ($device['EcoMinutes'] > 0) $hasEco = true;
                }
            }
        }
        
        $this->SetBuffer('WatchList', json_encode($watchList));
        
        // EcoTimer nur starten, wenn mindestens ein Gerät ein Timeout hat
        $this->SetTimerInterval('EcoTimer', $hasEco ? 60 * 1000 : 0);
        
        $this->SetSummary(count($list) . ' Geräte konfiguriert');
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE) {
            $val = (bool)$Data[0];
            
            // 1. LED Feedback
            $watchList = json_decode($this->GetBuffer('WatchList'), true);
            if (isset($watchList[$SenderID])) {
                foreach ($watchList[$SenderID] as $ledID) {
                    $this->SetLED($ledID, $val);
                    IPS_Sleep(50);
                }
            }

            // 2. ECO Timeout Logik
            $this->HandleEcoTracking($SenderID, $val);
        }
    }

    private function HandleEcoTracking(int $varID, bool $val): void
    {
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        $ecoTable = json_decode($this->GetBuffer('EcoTable'), true);

        if ($val) {
            // Gerät wurde eingeschaltet -> Timer suchen
            foreach ($list as $device) {
                if ($device['DeviceID'] == $varID && $device['EcoMinutes'] > 0) {
                    $offAt = time() + ($device['EcoMinutes'] * 60);
                    $ecoTable[$varID] = $offAt;
                }
            }
        } else {
            // Gerät wurde ausgeschaltet -> Timer entfernen
            unset($ecoTable[$varID]);
        }
        $this->SetBuffer('EcoTable', json_encode($ecoTable));
    }

    public function EcoTick(): void
    {
        $ecoTable = json_decode($this->GetBuffer('EcoTable'), true);
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        $now = time();
        $changed = false;

        foreach ($ecoTable as $varID => $offAt) {
            if ($now >= $offAt) {
                // Zeit abgelaufen!
                foreach ($list as $device) {
                    if ($device['DeviceID'] == $varID) {
                        $name = IPS_GetName(IPS_GetParent($varID));
                        $this->SwitchDevice((int)$device['DeviceID'], $device['DeviceType'], false);
                        
                        $this->SendPushover("ECO-Timeout: {$name} wurde nach {$device['EcoMinutes']} Min. automatisch ausgeschaltet.");
                        unset($ecoTable[$varID]);
                        $changed = true;
                        break;
                    }
                }
            }
        }

        if ($changed) {
            $this->SetBuffer('EcoTable', json_encode($ecoTable));
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'State') {
            $this->Execute($Value);
        }
    }

    public function Execute(bool $Status): void
    {
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        if (!is_array($list)) return;

        $switchedIDs = []; 
        foreach ($list as $device) {
            if (!$device['Enabled'] || !$device['UseAllesAus']) continue;
            
            $targetID = (int)$device['DeviceID'];
            if ($device['DeviceType'] !== 'chromo' && IPS_VariableExists($targetID)) {
                $targetID = IPS_GetParent($targetID);
            }

            if (in_array($targetID, $switchedIDs)) continue;
            $this->SwitchDevice((int)$device['DeviceID'], $device['DeviceType'], $Status);
            $switchedIDs[] = $targetID;
            IPS_Sleep(100); 
        }
    }

    public function SyncLEDs(): void
    {
        $watchList = json_decode($this->GetBuffer('WatchList'), true);
        foreach ($watchList as $varID => $ledIDs) {
            if (IPS_VariableExists($varID)) {
                $val = (bool)GetValue($varID);
                foreach ($ledIDs as $ledID) {
                    $this->SetLED($ledID, $val);
                    IPS_Sleep(50);
                }
            }
        }
    }

    private function SetLED(int $ledID, bool $state): void
    {
        if ($ledID > 0 && IPS_VariableExists($ledID)) {
            @RequestAction($ledID, $state);
        }
    }

    private function SwitchDevice(int $id, string $type, bool $status): void
    {
        $targetID = (IPS_VariableExists($id) && $type !== 'chromo') ? IPS_GetParent($id) : $id;
        try {
            switch ($type) {
                case 'parent': @HM_WriteValueBoolean($targetID, 'STATE', $status); break;
                case 'dimmer': @HM_WriteValueFloat($targetID, 'LEVEL', $status ? 1.0 : 0.0); break;
                case 'chromo': if (IPS_ScriptExists($id)) @IPS_RunScriptEx($id, ['StatusLicht' => $status]); break;
                case 'request': @RequestAction($id, $status); break;
            }
        } catch (Exception $e) {
            $this->SendDebug('Error', "Schaltfehler ID $targetID", 0);
        }
    }

    private function SendPushover(string $message): void
    {
        $inst = $this->ReadPropertyInteger('PushoverInstance');
        if ($inst <= 0 || !IPS_InstanceExists($inst)) return;

        $title = $this->ReadPropertyString('PushTitle');
        if (function_exists('TUPO_SendMessage')) {
            @TUPO_SendMessage($inst, $title, $message, 0);
        }
    }
}