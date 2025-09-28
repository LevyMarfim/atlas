<?php
// src/Controller/HomeController.php

namespace App\Controller;

use App\Form\PdfUploadType;
use App\Service\PdfTextExtractor;
use Smalot\PdfParser\Parser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(Request $request, SluggerInterface $slugger): Response
    {
        $form = $this->createForm(PdfUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $pdfFile = $form->get('pdf_file')->getData();

            if ($pdfFile) {
                $originalFilename = pathinfo($pdfFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$pdfFile->guessExtension();

                try {
                    $pdfFile->move(
                        $this->getParameter('pdf_directory'),
                        $newFilename
                    );
                    
                    $this->addFlash('success', 'PDF uploaded successfully!');
                    
                    // Redirect to options page after upload
                    return $this->redirectToRoute('app_pdf_options', ['filename' => $newFilename]);
                } catch (FileException $e) {
                    $this->addFlash('error', 'There was an error uploading your file.');
                }
            }
        }

        return $this->render('home/index.html.twig', [
            'uploadForm' => $form->createView(),
        ]);
    }

    #[Route('/pdf/{filename}/options', name: 'app_pdf_options')]
    public function pdfOptions(string $filename): Response
    {
        $pdfPath = $this->getParameter('pdf_directory').'/'.$filename;
        
        if (!file_exists($pdfPath)) {
            throw $this->createNotFoundException('The PDF file does not exist');
        }

        return $this->render('home/pdf_options.html.twig', [
            'filename' => $filename,
        ]);
    }

    #[Route('/pdf/{filename}/view', name: 'app_view_pdf')]
    public function viewPdf(string $filename): Response
    {
        $pdfPath = $this->getParameter('pdf_directory').'/'.$filename;
        
        if (!file_exists($pdfPath)) {
            throw $this->createNotFoundException('The PDF file does not exist');
        }

        return $this->render('home/view_pdf.html.twig', [
            'filename' => $filename,
            'pdfPath' => $pdfPath,
        ]);
    }

    #[Route('/pdf/{filename}/extract-text', name: 'app_extract_text')]
    public function extractText(string $filename, PdfTextExtractor $pdfExtractor, Request $request): Response
    {
        $pdfPath = $this->getParameter('pdf_directory').'/'.$filename;
        
        if (!file_exists($pdfPath)) {
            throw $this->createNotFoundException('The PDF file does not exist');
        }

        $method = $request->query->get('method', 'auto');
        $availableMethods = $pdfExtractor->getAvailableMethods();

        $result = $pdfExtractor->extractText($pdfPath, $method);

        // Safely access array keys with defaults
        $text = $result['text'] ?? '';
        $warnings = $result['warnings'] ?? [];
        $methodUsed = $result['method_used'] ?? 'unknown';
        $confidence = $result['confidence'] ?? 'none';
        $metadata = $result['metadata'] ?? [];

        // Calculate statistics
        $wordCount = str_word_count($text);
        $charCount = strlen($text);
        $lineCount = substr_count($text, "\n") + 1;

        // Prepare error message
        $error = null;
        if (!empty($warnings)) {
            $error = is_array($warnings) ? implode(', ', $warnings) : $warnings;
        }

        return $this->render('home/extract_text.html.twig', [
            'filename' => $filename,
            'extractedText' => $text,
            'error' => $error,
            'textLength' => $charCount,
            'wordCount' => $wordCount,
            'lineCount' => $lineCount,
            'methodUsed' => $methodUsed,
            'confidence' => $confidence,
            'metadata' => $metadata,
            'availableMethods' => $availableMethods,
            'currentMethod' => $method,
        ]);
    }

    #[Route('/pdf/{filename}/extract-text/method', name: 'app_extract_text_method', methods: ['POST'])]
    public function extractTextWithMethod(string $filename, PdfTextExtractor $pdfExtractor, Request $request): Response
    {
        $method = $request->request->get('method', 'auto');
        return $this->redirectToRoute('app_extract_text', [
            'filename' => $filename,
            'method' => $method
        ]);
    }

    #[Route('/pdf/download/{filename}', name: 'app_download_pdf')]
    public function downloadPdf(string $filename): Response
    {
        $pdfPath = $this->getParameter('pdf_directory').'/'.$filename;
        
        if (!file_exists($pdfPath)) {
            throw $this->createNotFoundException('The PDF file does not exist');
        }

        return $this->file($pdfPath);
    }

    #[Route('/pdf/{filename}/download-text', name: 'app_download_text')]
    public function downloadText(string $filename, PdfTextExtractor $pdfExtractor, Request $request): Response
    {
        $pdfPath = $this->getParameter('pdf_directory').'/'.$filename;
        
        if (!file_exists($pdfPath)) {
            throw $this->createNotFoundException('The PDF file does not exist');
        }

        $method = $request->query->get('method', 'auto');
        $result = $pdfExtractor->extractText($pdfPath, $method);

        // Safely access text
        $text = $result['text'] ?? '';

        if (empty($text)) {
            $this->addFlash('error', 'No text could be extracted from this PDF');
            return $this->redirectToRoute('app_extract_text', ['filename' => $filename]);
        }

        $response = new Response($text);
        $response->headers->set('Content-Type', 'text/plain');
        $response->headers->set('Content-Disposition', 
            'attachment; filename="' . pathinfo($filename, PATHINFO_FILENAME) . '.txt"');

        return $response;
    }
}