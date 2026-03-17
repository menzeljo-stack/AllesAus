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

                // Da wir direkt die Variable wählen, registrieren wir sie sofort
                if (IPS_VariableExists($device['DeviceID'])) {
                    $this->RegisterMessage($device['DeviceID'], VM_UPDATE);
                    $watchList[$device['DeviceID']] = $device['LedVarID'];
                }
            }
        }
        
        $this->SetBuffer('WatchList', json_encode($watchList));
        $this->SetSummary(count($list) . ' Objekte in Überwachung');
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE) {
            $watchList = json_decode($this->GetBuffer('WatchList'), true);
            if (isset($watchList[$SenderID]) && $watchList[$SenderID] > 0) {
                $this->SetLED($watchList[$SenderID], (bool)$Data[0]);
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

        foreach ($list as $device) {
            if (!$device['Enabled'] || !$device['UseAllesAus']) continue;
            if ($OnlyPrimary && !$device['IsPrimary']) continue;

            $this->SwitchDevice((int)$device['DeviceID'], $device['DeviceType'], $Status);
        }
    }

    public function SyncLEDs(): void
    {
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        if (!is_array($list)) return;

        foreach ($list as $device) {
            if ($device['Enabled'] && $device['DeviceID'] > 0 && $device['LedVarID'] > 0) {
                $currentVal = GetValue($device['DeviceID']);
                $this->SetLED($device['LedVarID'], (bool)$currentVal);
            }
        }
    }

    private function SetLED(int $ledID, bool $state): void
    {
        if (IPS_VariableExists($ledID)) {
            @RequestAction($ledID, $state);
        }
    }

    private function SwitchDevice(int $id, string $type, bool $status): void
    {
        // Wir ermitteln die Instanz (Parent), falls eine Variable gewählt wurde
        // Bei "chromo" (Script) bleibt die ID wie sie ist.
        $targetID = $id;
        if ($type !== 'chromo' && IPS_VariableExists($id)) {
            $targetID = IPS_GetParent($id);
        }

        try {
            switch ($type) {
                case 'parent': 
                    @HM_WriteValueBoolean($targetID, 'STATE', $status); 
                    break;
                case 'dimmer': 
                    @HM_WriteValueFloat($targetID, 'LEVEL', $status ? 1.0 : 0.0); 
                    break;
                case 'chromo': 
                    if (IPS_ScriptExists($id)) {
                        @IPS_RunScriptEx($id, ['StatusLicht' => $status]);
                    } 
                    break;
                case 'request': 
                    @RequestAction($id, $status); 
                    break;
            }
        } catch (Exception $e) {
            $this->SendDebug('Error', "Schaltfehler bei ID $targetID", 0);
        }
    }
}