<?php

class AllesAus extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceList', '[]');
        $this->RegisterVariableBoolean('State', 'Status', '~Switch', 0);
        $this->EnableAction('State');
        
        // Interner Buffer für die Zuordnung StatusVar -> LED
        $this->SetBuffer('WatchList', json_encode([]));
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Alte Nachrichten löschen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        $watchList = [];

        if (is_array($list)) {
            foreach ($list as $device) {
                if (!$device['Enabled']) continue;

                $statusVarID = $this->ResolveStatusVariable($device['DeviceID']);
                
                if ($statusVarID > 0) {
                    $this->RegisterMessage($statusVarID, VM_UPDATE);
                    $watchList[$statusVarID] = $device['LedVarID'];
                }
            }
        }
        
        $this->SetBuffer('WatchList', json_encode($watchList));
        $this->SetSummary(count($list) . ' Geräte konfiguriert');
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE) {
            $watchList = json_decode($this->GetBuffer('WatchList'), true);
            if (isset($watchList[$SenderID])) {
                $ledID = $watchList[$SenderID];
                $this->SetLED($ledID, (bool)$Data[0]);
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
            if (!$device['Enabled'] || $device['LedVarID'] <= 0) continue;

            $statusVarID = $this->ResolveStatusVariable($device['DeviceID']);
            if ($statusVarID > 0) {
                $this->SetLED($device['LedVarID'], (bool)GetValue($statusVarID));
            }
        }
    }

    private function ResolveStatusVariable(int $targetID): int
    {
        if ($targetID <= 0) return 0;

        // Wenn es bereits eine Variable ist
        if (IPS_VariableExists($targetID)) {
            return $targetID;
        }

        // Wenn es eine Instanz ist, nach typischen Idents suchen
        if (IPS_InstanceExists($targetID)) {
            $idents = ['STATE', 'LEVEL', 'Status', 'Power'];
            foreach ($idents as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $targetID);
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    return $vid;
                }
            }
            
            // Fallback: Erste Boolean Variable suchen
            $children = IPS_GetChildrenIDs($targetID);
            foreach ($children as $child) {
                if (IPS_VariableExists($child)) {
                    $v = IPS_GetVariable($child);
                    if ($v['VariableType'] == 0) return $child; // 0 = Boolean
                }
            }
        }
        return 0;
    }

    private function SetLED(int $ledID, bool $state): void
    {
        if ($ledID > 0 && IPS_VariableExists($ledID)) {
            @RequestAction($ledID, $state);
        }
    }

    private function SwitchDevice(int $id, string $type, bool $status): void
    {
        try {
            switch ($type) {
                case 'parent': @HM_WriteValueBoolean($id, 'STATE', $status); break;
                case 'dimmer': @HM_WriteValueFloat($id, 'LEVEL', $status ? 1.0 : 0.0); break;
                case 'chromo': if (IPS_ScriptExists($id)) @IPS_RunScriptEx($id, ['StatusLicht' => $status]); break;
                case 'request': @RequestAction($id, $status); break;
            }
        } catch (Exception $e) {
            $this->SendDebug('Error', "Schaltfehler ID $id", 0);
        }
    }
}