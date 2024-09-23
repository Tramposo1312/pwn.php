<?php

class PawnCompiler
{
    private $tokens;
    private $currentTokenIndex;
    private $outputBuffer;
    private $logBuffer;
    private $errors;
    private $variables;

    public function __construct($input)
    {
        $this->tokens = $this->tokenize($input);
        $this->currentTokenIndex = 0;
        $this->outputBuffer = '';
        $this->logBuffer = '';
        $this->errors = [];
        $this->variables = [];
    }

    private function tokenize($input)
    {
        $this->log("Starting tokenization process...\n");
        $pattern = '/("[^"]*"|\s+|[{}();=]|\b\w+\b|.)/';
        preg_match_all($pattern, $input, $matches);
        $tokens = array_values(array_filter($matches[0], function ($token) {
            return $token !== '' && !ctype_space($token);
        }));
        $this->log("Tokenization completed. " . count($tokens) . " tokens found.\n");
        $this->log("Tokens: " . implode(' ', $tokens) . "\n\n");
        return $tokens;
    }

    private function getNextToken()
    {
        $token = isset($this->tokens[$this->currentTokenIndex])
            ? $this->tokens[$this->currentTokenIndex++]
            : null;
        $this->log("Getting next token: " . ($token !== null ? "'" . $token . "'" : 'null') . "\n");
        return $token;
    }

    private function peekNextToken()
    {
        $token = isset($this->tokens[$this->currentTokenIndex])
            ? $this->tokens[$this->currentTokenIndex]
            : null;
        $this->log("Peeking next token: " . ($token !== null ? "'" . $token . "'" : 'null') . "\n");
        return $token;
    }

    private function expectToken($expected)
    {
        $token = $this->getNextToken();
        if ($token !== $expected) {
            $this->addError("Expected '$expected', but got '$token'");
        }
        return $token;
    }

    private function addError($message)
    {
        $this->errors[] = "Error at token {$this->currentTokenIndex}: $message";
    }

    public function compile()
    {
        $this->log("Starting compilation process...\n");
        $this->outputBuffer .= "# Compiled from Pawn to Python\n\n";
        while (($token = $this->peekNextToken()) !== null) {
            if ($token === 'main') {
                $this->compileMainFunction();
            } else {
                $this->addError("Unexpected token outside of main function: '$token'");
                $this->getNextToken(); // Skip the unexpected token
            }
        }
        $this->log("Compilation process completed.\n");

        if (!empty($this->errors)) {
            $this->log("Compilation errors:\n" . implode("\n", $this->errors) . "\n");
            return false;
        }

        return $this->outputBuffer;
    }

    private function compileMainFunction()
    {
        $this->log("Compiling main function...\n");
        $this->outputBuffer .= "def main():\n";
        $this->expectToken('main');
        $this->expectToken('(');
        $this->expectToken(')');
        $this->expectToken('{');

        $indentation = 1;
        while (($token = $this->peekNextToken()) !== null && $token !== '}') {
            if ($this->isTypeDeclaration($token)) {
                $this->compileVariableDeclaration($indentation);
            } elseif ($token === 'print') {
                $this->compilePrintStatement($indentation);
            } elseif ($this->isReturn0Statement()) {
                $this->skipReturn0Statement();
            } else {
                $this->compileOtherStatement($indentation);
            }
        }
        $this->expectToken('}');

        $this->outputBuffer .= "\nif __name__ == \"__main__\":\n    main()\n";
        $this->log("Main function compilation completed.\n");
    }

    private function isTypeDeclaration($token)
    {
        return in_array($token, ['int', 'float', 'bool', 'string']);
    }

    private function compileVariableDeclaration($indentation)
    {
        $type = $this->getNextToken();
        $name = $this->getNextToken();
        $this->variables[$name] = $type;

        if ($this->peekNextToken() === '=') {
            $this->getNextToken(); // =
            $value = $this->getNextToken();
            $this->expectToken(';');
            $pythonDeclaration = str_repeat('    ', $indentation) . "$name = $value\n";
        } else {
            $this->expectToken(';');
            $defaultValue = $this->getDefaultValueForType($type);
            $pythonDeclaration = str_repeat('    ', $indentation) . "$name = $defaultValue\n";
        }

        $this->outputBuffer .= $pythonDeclaration;
        $this->log("Variable declaration compiled: {$pythonDeclaration}");
    }

    private function getDefaultValueForType($type)
    {
        switch ($type) {
            case 'int':
                return '0';
            case 'float':
                return '0.0';
            case 'bool':
                return 'False';
            case 'string':
                return '""';
            default:
                $this->addError("Unknown type: $type");
                return 'None';
        }
    }

    private function compilePrintStatement($indentation)
    {
        $this->log("Compiling print statement...\n");
        $this->expectToken('print');
        $this->expectToken('(');
        $content = $this->getNextToken(); // the argument
        if (preg_match('/^".*"$/', $content)) {
            // It's a string literal, keep it as is
        } elseif (isset($this->variables[$content])) {
            // It's a variable, we need to format it for Python
            $type = $this->variables[$content];
            if ($type === 'string') {
                $content = "str($content)";
            } elseif ($type === 'int' || $type === 'float') {
                $content = "str($content)";
            } elseif ($type === 'bool') {
                $content = "'True' if $content else 'False'";
            }
        } else {
            $this->addError("Invalid print argument: $content. Expected a string literal or a declared variable.");
        }
        $this->expectToken(')');
        $pythonPrint = str_repeat('    ', $indentation) . "print(" . $content . ")\n";
        $this->outputBuffer .= $pythonPrint;
        $this->expectToken(';');
        $this->log("Print statement compiled: {$pythonPrint}");
    }

    private function isReturn0Statement()
    {
        return $this->peekNextToken() === 'return' &&
            isset($this->tokens[$this->currentTokenIndex + 1]) &&
            $this->tokens[$this->currentTokenIndex + 1] === '0';
    }

    private function skipReturn0Statement()
    {
        $this->log("Encountered 'return 0;' statement. Skipping in Python output...\n");
        $this->expectToken('return');
        $this->expectToken('0');
        $this->expectToken(';');
        $this->log("'return 0;' statement skipped.\n");
    }

    private function compileOtherStatement($indentation)
    {
        $this->log("Compiling other statement...\n");
        $statement = '';
        while (($token = $this->getNextToken()) !== null && $token !== ';') {
            $statement .= $token . ' ';
        }
        $statement = rtrim($statement);
        if ($statement !== '') {
            // Check if it's an assignment
            if (preg_match('/^(\w+)\s*=\s*(.+)$/', $statement, $matches)) {
                $varName = $matches[1];
                $value = $matches[2];
                if (!isset($this->variables[$varName])) {
                    $this->addError("Assignment to undeclared variable: $varName");
                } else {
                    $pythonStatement = str_repeat('    ', $indentation) . "$varName = $value\n";
                    $this->outputBuffer .= $pythonStatement;
                    $this->log("Assignment compiled: {$pythonStatement}");
                }
            } else {
                $this->addError("Unrecognized statement: $statement");
            }
        }
        if ($token !== ';') {
            $this->addError("Expected ';' at the end of statement");
        }
    }

    private function log($message)
    {
        $this->logBuffer .= $message;
    }

    public function getLog()
    {
        return $this->logBuffer;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}

function compileToPython($inputFile, $outputFile, $logFile)
{
    if (!file_exists($inputFile)) {
        fwrite(STDERR, "Error: Input file '{$inputFile}' does not exist.\n");
        exit(1);
    }

    $pawnCode = file_get_contents($inputFile);
    $compiler = new PawnCompiler($pawnCode);
    $pythonCode = $compiler->compile();

    if ($pythonCode === false) {
        echo "Compilation failed. Errors:\n";
        foreach ($compiler->getErrors() as $error) {
            echo $error . "\n";
        }
        exit(1);
    }

    file_put_contents($outputFile, $pythonCode);
    file_put_contents($logFile, $compiler->getLog());

    echo "Compilation completed successfully.\n";
    echo "Python code saved to: {$outputFile}\n";
    echo "Compilation log saved to: {$logFile}\n";
}

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php " . $argv[0] . " <input_file.pwn> <output_file.py> <log_file.txt>\n");
    exit(1);
}

$inputFile = $argv[1];
$outputFile = $argv[2];
$logFile = $argv[3];

compileToPython($inputFile, $outputFile, $logFile);