<?php

class AllesAus extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceList', '[]');
        $this->RegisterVariableBoolean('State', 'Status', '~Switch', 0);
        $this->EnableAction('State');
        
        $this->SetBuffer('WatchList', json_encode([]));
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Bestehende Registrierungen löschen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        $watchList = [];

        if (is_array($list)) {
            foreach ($list as $device) {
                if (!$device['Enabled'] || $device['DeviceID'] <= 0) continue;

                if (IPS_VariableExists($device['DeviceID'])) {
                    $this->RegisterMessage($device['DeviceID'], VM_UPDATE);
                    
                    // Sammle alle LEDs (1 bis 4) für diese Status-Variable ein
                    for ($i = 1; $i <= 4; $i++) {
                        $ledKey = 'LedVarID' . $i;
                        if (isset($device[$ledKey]) && $device[$ledKey] > 0) {
                            $watchList[$device['DeviceID']][] = $device[$ledKey];
                        }
                    }
                }
            }
        }
        
        $this->SetBuffer('WatchList', json_encode($watchList));
        $this->SetSummary(count($list) . ' Geräte in der Liste');
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE) {
            $watchList = json_decode($this->GetBuffer('WatchList'), true);
            if (isset($watchList[$SenderID])) {
                foreach ($watchList[$SenderID] as $ledID) {
                    $this->SetLED($ledID, (bool)$Data[0]);
                    IPS_Sleep(50); // Funkhygiene für HomeMatic
                }
            }
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
            $type = $device['DeviceType'];

            $targetID = $id;
            if ($type !== 'chromo' && IPS_VariableExists($id)) {
                $targetID = IPS_GetParent($id);
            }

            if (in_array($targetID, $switchedIDs)) continue;

            $this->SwitchDevice($id, $type, $Status);
            $switchedIDs[] = $targetID;
            IPS_Sleep(100); 
        }
    }

    public function SyncLEDs(): void
    {
        $watchList = json_decode($this->GetBuffer('WatchList'), true);
        if (!is_array($watchList)) return;

        foreach ($watchList as $varID => $ledIDs) {
            if (IPS_VariableExists($varID)) {
                $currentVal = (bool)GetValue($varID);
                foreach ($ledIDs as $ledID) {
                    $this->SetLED($ledID, $currentVal);
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
        $targetID = $id;
        if ($type !== 'chromo' && IPS_VariableExists($id)) {
            $targetID = IPS_GetParent($id);
        }

        try {
            switch ($type) {
                case 'parent': @HM_WriteValueBoolean($targetID, 'STATE', $status); break;
                case 'dimmer': @HM_WriteValueFloat($targetID, 'LEVEL', $status ? 1.0 : 0.0); break;
                case 'chromo': if (IPS_ScriptExists($id)) @IPS_RunScriptEx($id, ['StatusLicht' => $status]); break;
                case 'request': @RequestAction($id, $status); break;
            }
        } catch (Exception $e) {
            $this->SendDebug('Error', "Schaltfehler bei ID $targetID", 0);
        }
    }
}