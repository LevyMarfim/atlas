<?php
// src/Controller/HomeController.php

namespace App\Controller;

use App\Form\PdfUploadType;
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
    public function extractText(string $filename): Response
    {
        $pdfPath = $this->getParameter('pdf_directory').'/'.$filename;
        
        if (!file_exists($pdfPath)) {
            throw $this->createNotFoundException('The PDF file does not exist');
        }

        $text = '';
        $error = null;

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($pdfPath);
            $text = $pdf->getText();
            
            // Clean up the text - remove excessive whitespace
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);
            
            if (empty($text)) {
                $error = 'No text could be extracted from this PDF. The PDF might be image-based or scanned.';
            }
            
        } catch (\Exception $e) {
            $error = 'Error extracting text: ' . $e->getMessage();
        }

        return $this->render('home/extract_text.html.twig', [
            'filename' => $filename,
            'extractedText' => $text,
            'error' => $error,
            'textLength' => strlen($text),
            'wordCount' => str_word_count($text),
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
    public function downloadText(string $filename): Response
    {
        $pdfPath = $this->getParameter('pdf_directory').'/'.$filename;
        
        if (!file_exists($pdfPath)) {
            throw $this->createNotFoundException('The PDF file does not exist');
        }

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($pdfPath);
            $text = $pdf->getText();
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);

            if (empty($text)) {
                throw new \Exception('No text could be extracted');
            }

            $response = new Response($text);
            $response->headers->set('Content-Type', 'text/plain');
            $response->headers->set('Content-Disposition', 
                'attachment; filename="' . pathinfo($filename, PATHINFO_FILENAME) . '.txt"');

            return $response;

        } catch (\Exception $e) {
            $this->addFlash('error', 'Error extracting text: ' . $e->getMessage());
            return $this->redirectToRoute('app_pdf_options', ['filename' => $filename]);
        }
    }
}