<?php
// Module.php

// Improved module code with error handling, debugging, validation, and modular functions

class Module {
    // Property to hold debug messages
    private $debugMessages = [];

    // Method to log debug messages
    private function logDebug($message) {
        $this->debugMessages[] = $message;
    }

    // Method to retrieve debug messages
    public function getDebugMessages() {
        return $this->debugMessages;
    }

    // Method to validate input
    public function validateInput($input) {
        if (empty($input)) {
            $this->logDebug("Validation failed: Input is empty.");
            return false;
        }
        $this->logDebug("Validation passed: Input is valid.");
        return true;
    }

    // Example function
    public function process($input) {
        if (!$this->validateInput($input)) {
            throw new Exception('Invalid input');
        }
        // Process the input...
        $this->logDebug("Processing input: $input");
        return "Processed: $input";
    }
}

// Example usage
try {
    $module = new Module();
    $result = $module->process('Sample Input');
    echo $result;
    print_r($module->getDebugMessages());
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}