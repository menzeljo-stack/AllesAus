<?php

class Lichtsteuerung extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceList', '[]');
        $this->RegisterPropertyInteger('PushoverInstance', 0);
        $this->RegisterPropertyString('PushTitle', 'Lichtsteuerung ECO');

        $this->RegisterVariableBoolean('State', 'Zentral-Schalter', '~Switch', 0);
        $this->EnableAction('State');
        
        $this->SetBuffer('WatchList', json_encode([]));
        $this->SetBuffer('EcoTable', json_encode([]));

        $this->RegisterTimer('EcoTimer', 0, "LST_EcoTick(\$_IPS['TARGET']);");
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
                    
                    for ($i = 1; $i <= 4; $i++) {
                        $key = 'LedVarID' . $i;
                        if (isset($device[$key]) && $device[$key] > 0) {
                            $watchList[$device['DeviceID']][] = $device[$key];
                        }
                    }
                    if ($device['EcoMinutes'] > 0) $hasEco = true;
                }
            }
        }
        
        $this->SetBuffer('WatchList', json_encode($watchList));
        $this->SetTimerInterval('EcoTimer', $hasEco ? 60 * 1000 : 0);
        $this->SetSummary(count($list) . ' Geräte aktiv');
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE) {
            $val = (bool)$Data[0];
            $watchList = json_decode($this->GetBuffer('WatchList'), true);
            if (isset($watchList[$SenderID])) {
                foreach ($watchList[$SenderID] as $ledID) {
                    $this->SetLED($ledID, $val);
                    IPS_Sleep(50);
                }
            }
            $this->HandleEcoTracking($SenderID, $val);
        }
    }

    private function HandleEcoTracking(int $varID, bool $val): void
    {
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        $ecoTable = json_decode($this->GetBuffer('EcoTable'), true);

        if ($val) {
            foreach ($list as $device) {
                if ($device['DeviceID'] == $varID && $device['EcoMinutes'] > 0) {
                    $ecoTable[$varID] = time() + ($device['EcoMinutes'] * 60);
                }
            }
        } else {
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
                foreach ($list as $device) {
                    if ($device['DeviceID'] == $varID) {
                        $name = IPS_GetName(IPS_GetParent($varID));
                        $this->SwitchDevice((int)$device['DeviceID'], $device['DeviceType'], false);
                        $this->SendPushover("ECO-Timeout: {$name} wurde automatisch ausgeschaltet.");
                        unset($ecoTable[$varID]);
                        $changed = true;
                        break;
                    }
                }
            }
        }
        if ($changed) $this->SetBuffer('EcoTable', json_encode($ecoTable));
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'State') $this->Execute($Value);
    }

    public function Execute(bool $Status): void
    {
        $this->RunSwitching($Status, false);
    }

    public function ExecutePrimary(bool $Status): void
    {
        $this->RunSwitching($Status, true);
    }

    private function RunSwitching(bool $Status, bool $OnlyPrimary): void
    {
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        if (!is_array($list)) return;

        $this->SetValue('State', $Status);
        $switchedIDs = [];
        foreach ($list as $device) {
            if (!$device['Enabled'] || !$device['UseAllesAus']) continue;
            if ($OnlyPrimary && !$device['IsPrimary']) continue;
            
            $id = (int)$device['DeviceID'];
            if (in_array($id, $switchedIDs)) continue;

            $this->SwitchDevice($id, $device['DeviceType'], $Status);
            $switchedIDs[] = $id;
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
        if ($ledID > 0 && IPS_VariableExists($ledID)) @RequestAction($ledID, $state);
    }

    private function SwitchDevice(int $id, string $type, bool $status): void
    {
        $targetID = ($type == 'parent' || $type == 'dimmer') ? IPS_GetParent($id) : $id;

        try {
            switch ($type) {
                case 'parent': @HM_WriteValueBoolean($targetID, 'STATE', $status); break;
                case 'dimmer': @HM_WriteValueFloat($targetID, 'LEVEL', $status ? 1.0 : 0.0); break;
                case 'chromo': if (IPS_ScriptExists($id)) @IPS_RunScriptEx($id, ['StatusLicht' => $status]); break;
                case 'request': @RequestAction($id, $status); break;
            }
        } catch (Exception $e) {
            $this->SendDebug('Error', "Schaltfehler ID $id", 0);
        }
    }

    private function SendPushover(string $message): void
    {
        $inst = $this->ReadPropertyInteger('PushoverInstance');
        if ($inst <= 0 || !IPS_InstanceExists($inst)) return;
        if (function_exists('TUPO_SendMessage')) @TUPO_SendMessage($inst, $this->ReadPropertyString('PushTitle'), $message, 0);
    }
}