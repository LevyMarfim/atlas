<?php
// src/Service/PdfTextExtractor.php

namespace App\Service;

use Smalot\PdfParser\Parser;
use Spatie\PdfToText\Pdf;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PdfTextExtractor
{
    private $pdfParser;
    private $projectDir;

    public function __construct(string $projectDir)
    {
        $this->pdfParser = new Parser();
        $this->projectDir = $projectDir;
    }

    public function extractText(string $pdfPath, string $method = 'auto'): array
    {
        // Initialize result with all required keys
        $result = [
            'text' => '',
            'method_used' => 'none',
            'confidence' => 'none',
            'warnings' => [],
            'metadata' => [],
        ];

        try {
            switch ($method) {
                case 'auto':
                    $result = $this->tryAllMethods($pdfPath);
                    break;
                case 'pdfparser':
                    $result = $this->extractWithPdfParser($pdfPath);
                    break;
                case 'pdftotext':
                    $result = $this->extractWithPdftotext($pdfPath);
                    break;
                case 'ocr':
                    $result = $this->extractWithOcr($pdfPath);
                    break;
                case 'fallback':
                    $result = $this->extractWithFallback($pdfPath);
                    break;
                default:
                    $result = $this->tryAllMethods($pdfPath);
            }
        } catch (\Exception $e) {
            $result['warnings'][] = 'Extraction failed: ' . $e->getMessage();
            $result['confidence'] = 'none';
        }

        // Ensure all keys exist and clean up the text
        $result = $this->ensureResultStructure($result);
        
        if (!empty($result['text'])) {
            $result['text'] = $this->cleanText($result['text']);
        }

        return $result;
    }

    private function ensureResultStructure(array $result): array
    {
        $defaults = [
            'text' => '',
            'method_used' => 'none',
            'confidence' => 'none',
            'warnings' => [],
            'metadata' => [],
        ];

        foreach ($defaults as $key => $defaultValue) {
            if (!array_key_exists($key, $result)) {
                $result[$key] = $defaultValue;
            }
        }

        return $result;
    }

    private function tryAllMethods(string $pdfPath): array
    {
        $methods = ['pdfparser', 'pdftotext', 'fallback', 'ocr'];
        
        foreach ($methods as $method) {
            try {
                $result = [];
                
                switch ($method) {
                    case 'pdfparser':
                        $result = $this->extractWithPdfParser($pdfPath);
                        if (!empty($result['text']) && $this->isGoodQualityText($result['text'])) {
                            $result['method_used'] = 'pdfparser (primary)';
                            return $this->ensureResultStructure($result);
                        }
                        break;
                    case 'pdftotext':
                        $result = $this->extractWithPdftotext($pdfPath);
                        if (!empty($result['text']) && $this->isGoodQualityText($result['text'])) {
                            $result['method_used'] = 'pdftotext (system tool)';
                            return $this->ensureResultStructure($result);
                        }
                        break;
                    case 'fallback':
                        $result = $this->extractWithFallback($pdfPath);
                        if (!empty($result['text'])) {
                            $result['method_used'] = 'fallback (basic)';
                            $result['confidence'] = 'medium';
                            return $this->ensureResultStructure($result);
                        }
                        break;
                    case 'ocr':
                        $result = $this->extractWithOcr($pdfPath);
                        if (!empty($result['text'])) {
                            $result['method_used'] = 'ocr (image-based)';
                            $result['confidence'] = 'low';
                            return $this->ensureResultStructure($result);
                        }
                        break;
                }
            } catch (\Exception $e) {
                // Continue to next method
                continue;
            }
        }

        return [
            'text' => '',
            'method_used' => 'none',
            'confidence' => 'none',
            'warnings' => ['All extraction methods failed'],
            'metadata' => [],
        ];
    }

    private function extractWithPdfParser(string $pdfPath): array
    {
        $pdf = $this->pdfParser->parseFile($pdfPath);
        $text = $pdf->getText();
        $details = $pdf->getDetails();

        return [
            'text' => $text,
            'method_used' => 'pdfparser',
            'confidence' => 'high',
            'metadata' => $details,
            'warnings' => [],
        ];
    }

    private function extractWithPdftotext(string $pdfPath): array
    {
        // Check if pdftotext is available
        $process = new Process(['which', 'pdftotext']);
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new \Exception('pdftotext command not found');
        }

        $text = Pdf::getText($pdfPath);
        
        return [
            'text' => $text,
            'method_used' => 'pdftotext',
            'confidence' => 'high',
            'warnings' => [],
            'metadata' => [],
        ];
    }

    private function extractWithOcr(string $pdfPath): array
    {
        // First convert PDF to images, then OCR
        $images = $this->convertPdfToImages($pdfPath);
        $fullText = '';

        foreach ($images as $imagePath) {
            $text = (new TesseractOCR($imagePath))
                ->lang('eng')
                ->run();
            $fullText .= $text . "\n\n";
            
            // Clean up temporary image file
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }

        return [
            'text' => $fullText,
            'method_used' => 'ocr',
            'confidence' => 'low',
            'warnings' => ['OCR used - may contain recognition errors'],
            'metadata' => [],
        ];
    }

    private function extractWithFallback(string $pdfPath): array
    {
        // Simple fallback using shell commands
        $methods = [
            'strings' => ['strings', $pdfPath],
            'pdftohtml' => ['pdftohtml', '-stdout', '-i', $pdfPath],
        ];

        foreach ($methods as $method => $command) {
            try {
                $process = new Process($command);
                $process->run();
                
                if ($process->isSuccessful()) {
                    $text = $process->getOutput();
                    if (!empty(trim($text))) {
                        return [
                            'text' => $text,
                            'method_used' => $method,
                            'confidence' => 'low',
                            'warnings' => ['Used fallback method - quality may be poor'],
                            'metadata' => [],
                        ];
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        throw new \Exception('Fallback methods failed');
    }

    private function convertPdfToImages(string $pdfPath): array
    {
        $outputDir = sys_get_temp_dir() . '/pdf_images_' . uniqid();
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $process = new Process([
            'pdftoppm', 
            '-png', 
            '-r', '300', 
            $pdfPath, 
            $outputDir . '/page'
        ]);
        
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('PDF to image conversion failed');
        }

        $images = glob($outputDir . '/page-*.png');
        sort($images);

        return $images;
    }

    private function cleanText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Fix common OCR issues
        $replacements = [
            '/\b([Il1])([\'`´ʼʻʹʾʻʼ])([l1])\b/' => 'I\'ll', // I'll fix
            '/\b([Tt])([\'`´ʼʻʹʾʻʼ])([s5])\b/' => 't\'s',  // t's fix
        ];
        
        $text = preg_replace(array_keys($replacements), array_values($replacements), $text);
        
        return trim($text);
    }

    private function isGoodQualityText(string $text): bool
    {
        // Basic quality checks
        $text = trim($text);
        
        if (empty($text)) {
            return false;
        }

        // Check if text has reasonable word lengths
        $words = str_word_count($text);
        if ($words < 10) {
            return false;
        }

        // Check for common PDF extraction artifacts
        $badPatterns = [
            '/\b\w\b/', // Single letters everywhere
            '/\b\w\w\b/', // Too many two-letter words
        ];

        foreach ($badPatterns as $pattern) {
            if (preg_match_all($pattern, $text) > ($words * 0.3)) {
                return false;
            }
        }

        return true;
    }

    public function getAvailableMethods(): array
    {
        $methods = [
            'auto' => 'Auto-detect (Recommended)',
            'pdfparser' => 'PDF Parser (Pure PHP)',
            'pdftotext' => 'System pdftotext (Fast)',
        ];

        // Check if OCR is available
        try {
            new TesseractOCR();
            $methods['ocr'] = 'OCR (For scanned PDFs)';
        } catch (\Exception $e) {
            // OCR not available
        }

        // Check if fallback methods are available
        try {
            $process = new Process(['which', 'strings']);
            $process->run();
            if ($process->isSuccessful()) {
                $methods['fallback'] = 'Fallback (Basic extraction)';
            }
        } catch (\Exception $e) {
            // Fallback not available
        }

        return $methods;
    }
}