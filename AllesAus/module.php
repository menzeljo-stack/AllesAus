<?php

class AllesAus extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceList', '[]');
        $this->RegisterVariableBoolean('State', 'Status', '~Switch', 0);
        $this->EnableAction('State');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Alle alten Registrierungen löschen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Neue Variablen zur Überwachung registrieren
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        if (is_array($list)) {
            foreach ($list as $device) {
                if ($device['Enabled'] && $device['StatusVarID'] > 0) {
                    $this->RegisterMessage($device['StatusVarID'], VM_UPDATE);
                }
            }
        }
        
        $this->SetSummary(count($list) . ' Geräte verwaltet');
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // Wenn sich eine überwachte Variable ändert
        if ($Message == VM_UPDATE) {
            $this->UpdateSingleLED($SenderID, $Data[0]);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'State') {
            $this->Execute($Value);
        }
    }

    /**
     * Schaltet nur Geräte, bei denen 'UseAllesAus' aktiviert ist
     */
    public function Execute(bool $Status, bool $OnlyPrimary = false): void
    {
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        if (!is_array($list)) return;

        foreach ($list as $device) {
            if (!$device['Enabled'] || !$device['UseAllesAus']) continue;
            if ($OnlyPrimary && !$device['IsPrimary']) continue;

            $this->SwitchDevice((int)$device['InstanceID'], $device['DeviceType'], $Status);
        }
    }

    /**
     * Manuelle Synchronisation aller LEDs
     */
    public function SyncLEDs(): void
    {
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        if (!is_array($list)) return;

        foreach ($list as $device) {
            if ($device['Enabled'] && $device['StatusVarID'] > 0 && $device['LedVarID'] > 0) {
                $currentVal = GetValue($device['StatusVarID']);
                $this->SetLED($device['LedVarID'], (bool)$currentVal);
            }
        }
    }

    private function UpdateSingleLED(int $varID, $value): void
    {
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        foreach ($list as $device) {
            if ($device['Enabled'] && $device['StatusVarID'] == $varID && $device['LedVarID'] > 0) {
                $this->SetLED($device['LedVarID'], (bool)$value);
            }
        }
    }

    private function SetLED(int $ledID, bool $state): void
    {
        if (IPS_VariableExists($ledID)) {
            // RequestAction ist am sichersten für alle Typen
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