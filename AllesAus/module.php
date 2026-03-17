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
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        $this->SetSummary((is_array($list) ? count($list) : 0) . ' Geräte konfiguriert');
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'State':
                $this->Execute($Value, false);
                break;
            default:
                throw new Exception("Invalid Ident: " . $Ident);
        }
    }

    // WICHTIG: "= false" macht den Parameter optional für ALOA_Execute($id, $status)
    public function Execute(bool $Status, bool $OnlyPrimary = false): void
    {
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        if (!is_array($list)) return;

        $this->SetValue('State', $Status);

        foreach ($list as $device) {
            if (!isset($device['Enabled']) || !$device['Enabled']) continue;
            
            $id = (int)$device['InstanceID'];
            $type = $device['DeviceType'];
            $isPrimary = (bool)($device['IsPrimary'] ?? false);

            if ($OnlyPrimary && !$isPrimary) continue;
            if ($type !== 'chromo' && !IPS_InstanceExists($id)) continue;

            $this->SwitchDevice($id, $type, $Status);
        }
    }

    private function SwitchDevice(int $id, string $type, bool $status): void
    {
        $dimValue = $status ? 1.0 : 0.0;
        try {
            switch ($type) {
                case 'parent': @HM_WriteValueBoolean($id, 'STATE', $status); break;
                case 'dimmer': @HM_WriteValueFloat($id, 'LEVEL', $dimValue); break;
                case 'chromo': if (IPS_ScriptExists($id)) @IPS_RunScriptEx($id, ['StatusLicht' => $status]); break;
                case 'request': @RequestAction($id, $status); break;
            }
        } catch (Exception $e) {
            $this->SendDebug('Error', "Fehler bei ID $id: " . $e->getMessage(), 0);
        }
    }
}