<?php

class AllesAus extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Eigenschaften registrieren
        $this->RegisterPropertyString('DeviceList', '[]');
        
        // Statusvariable für das WebFront
        $this->RegisterVariableBoolean('State', 'Status', '~Switch', 0);
        $this->EnableAction('State');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        $count = is_array($list) ? count($list) : 0;
        
        $this->SetSummary($count . ' Geräte konfiguriert');
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'State':
                // Schaltet alles (Primary irrelevant beim manuellen WebFront-Schalten)
                $this->Execute($Value, false);
                break;

            default:
                throw new Exception("Invalid Ident: " . $Ident);
        }
    }

    /**
     * Hauptfunktion zum Schalten der Liste
     * * @param bool $Status Der Zielzustand (True = An, False = Aus)
     * @param bool $OnlyPrimary Falls True, werden nur Geräte mit aktivem 'IsPrimary' geschaltet
     */
    public function Execute(bool $Status, bool $OnlyPrimary = false): void
    {
        $list = json_decode($this->ReadPropertyString('DeviceList'), true);
        if (!is_array($list)) {
            return;
        }

        // Lokalen Status aktualisieren
        $this->SetValue('State', $Status);

        foreach ($list as $device) {
            // Überspringen, wenn deaktiviert
            if (!isset($device['Enabled']) || !$device['Enabled']) {
                continue;
            }
            
            $id = (int)$device['InstanceID'];
            $type = $device['DeviceType'];
            $primary = (bool)($device['IsPrimary'] ?? false);

            // Filter für "Nur Hauptgeräte"
            if ($OnlyPrimary && !$primary) {
                continue;
            }

            // Existenzprüfung
            if ($type !== 'chromo' && !IPS_InstanceExists($id)) {
                continue;
            }

            $this->SwitchDevice($id, $type, $Status);
        }
    }

    /**
     * Führt den typspezifischen Schaltbefehl aus
     */
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
                    // Nutzt RequestAction für moderne Module (Shelly, MQTT etc.)
                    @RequestAction($id, $status);
                    break;
            }
        } catch (Exception $e) {
            $this->SendDebug('Error', "Fehler bei ID $id: " . $e->getMessage(), 0);
        }
    }
}