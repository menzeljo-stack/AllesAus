<?php

class AllesAus extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Eigenschaften
        $this->RegisterPropertyString('DeviceList', '[]');
        
        // Statusvariable für Webfront
        $this->RegisterVariableBoolean('State', 'Status', '~Switch', 0);
        $this->EnableAction('State');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        $count = is_array($list) ? count($list) : 0;
        
        $this->SetSummary($count . ' Geräte in der Liste');
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'State':
                // Wir schalten alles basierend auf dem Wert (true = an, false = aus)
                $this->Execute($Value, false);
                break;

            default:
                throw new Exception("Invalid Ident: " . $Ident);
        }
    }

    /**
     * Hauptfunktion zum Schalten
     * @param bool $Status Der Zielzustand (True/False)
     * @param bool $OnlyPrimary Falls True, werden nur Geräte mit 'IsPrimary' geschaltet
     */
    public function Execute(bool $Status, bool $OnlyPrimary): void
    {
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        if (!is_array($list)) return;

        // Zustand in der eigenen Variable spiegeln
        $this->SetValue('State', $Status);

        foreach ($list as $device) {
            if (!$device['Enabled']) continue;
            
            $id = (int)$device['InstanceID'];
            $type = $device['DeviceType'];
            $primary = (bool)$device['IsPrimary'];

            // Filter für Primary-Logik
            if ($OnlyPrimary && !$primary) {
                continue;
            }

            if (!IPS_InstanceExists($id) && $type !== 'chromo') {
                continue;
            }

            $this->SwitchDevice($id, $type, $Status);
        }
    }

    private function SwitchDevice(int $id, string $type, bool $status): void
    {
        $dimValue = $status ? 1.0 : 0.0;

        try {
            switch ($type) {
                case 'parent':
                    @HM_WriteValueBoolean($id, 'STATE', $status);
                    break;

                case 'dimmer':
                    @HM_WriteValueFloat($id, 'LEVEL', $dimValue);
                    break;

                case 'chromo':
                    if (IPS_ScriptExists($id)) {
                        @IPS_RunScriptEx($id, ['StatusLicht' => $status]);
                    }
                    break;

                case 'request':
                    // Nutzt die Standard-Aktion der Instanz (funktioniert bei Shelly, Zigbee2Symcon, etc.)
                    @RequestAction($id, $status);
                    break;
            }
        } catch (Exception $e) {
            $this->SendDebug('Error', "Fehler beim Schalten von ID $id: " . $e->getMessage(), 0);
        }
    }
}