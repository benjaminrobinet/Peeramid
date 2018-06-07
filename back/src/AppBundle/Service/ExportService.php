<?php
/**
 * Created by PhpStorm.
 * User: Nicolas
 * Date: 13/12/2017
 * Time: 12:12
 */

namespace AppBundle\Service;


use AppBundle\Constants;
use AppBundle\Entity\Assignment;
use AppBundle\Entity\AssignmentCriteria;
use AppBundle\Entity\AssignmentSection;
use AppBundle\Entity\Correction;
use AppBundle\Entity\CorrectionCriteria;
use AppBundle\Entity\CorrectionOpinion;
use AppBundle\Entity\CorrectionSection;
use AppBundle\Entity\Criteria;
use AppBundle\Entity\CriteriaChoice;
use AppBundle\Entity\Evaluation;
use AppBundle\Entity\ExampleAssignment;
use AppBundle\Entity\Group;
use AppBundle\Entity\Section;
use AppBundle\Entity\SubjectFile;
use AppBundle\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use ZipArchive;

class ExportService
{
    /** @var ContainerInterface */
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @param $zipName
     * @param Evaluation $evaluation
     * @return ZipArchive
     */
    public function buildStatsZip($zipName, Evaluation $evaluation)
    {
        // ZIP
        $zip = new ZipArchive;
        $zip->open($zipName, ZipArchive::CREATE);

        $csvContent = $this->buildCriteriaStatsCsv($evaluation);
        $csvName = sprintf("Export_evaluation_stats_%d_%s.csv", $evaluation->getId(), date("Ymd_his"));
        $zip->addFromString($csvName, $csvContent);

        $csvQuality = $this->buildQualityCsv($evaluation);
        $csvName = sprintf("Export_evaluation_quality_%d_%s.csv", $evaluation->getId(), date("Ymd_his"));
        $zip->addFromString($csvName, $csvQuality);

        $zip->close();

        return $zip;
    }

    private function buildCriteriaStatsCsv(Evaluation $evaluation)
    {
        $table = array();
        /** @var Assignment $assignment */
        foreach ($evaluation->getAssignments() as $assignment) {
            /** @var Correction $correction */
            foreach ($assignment->getCorrections() as $correction) {
                if ($correction->getUser()) {
                    if ($correction->getUser()->getRole()->getId() == Constants::ROLE_TEACHER) {
                        $correctionTeacher = clone $correction;
                        break;
                    }
                }
            }
            foreach ($assignment->getCorrections() as $correction) {
                if ($correction->isStudentCorrection()) {
                    $line = array();
                    $line['Auteur'] = utf8_decode($assignment->getAuthorName());
                    $line['Correcteur'] = utf8_decode($correction->getCorrectorName());
                    $draft = $correction->isDraft();
                    /** @var ArrayCollection $correctionSections */
                    $correctionSections = $correction->getCorrectionSections();
                    // Sort correctionSections by section order
                    $iterator = $correctionSections->getIterator();
                    $iterator->uasort(function ($a, $b) {
                        /** @var CorrectionSection $a */
                        /** @var CorrectionSection $b */
                        /** @var Section $sectionA */
                        $sectionA = $a->getAssignmentSection()->getSection();
                        /** @var Section $sectionB */
                        $sectionB = $b->getAssignmentSection()->getSection();
                        return ($sectionA->getOrder() < $sectionB->getOrder() ? -1 : 1);
                    });
                    $correctionSections = new ArrayCollection(iterator_to_array($iterator));
                    $sectionIndex = 1;
                    /** @var CorrectionSection $correctionSection */
                    foreach ($correctionSections as $correctionSection) {
                        $criteriaIndex = 1;
                        // Sort correctionCriterias by criteria order
                        /** @var ArrayCollection $correctionCriterias */
                        $correctionCriterias = $correctionSection->getCorrectionCriterias();
                        $iterator = $correctionCriterias->getIterator();
                        $iterator->uasort(function ($a, $b) {
                            /** @var CorrectionCriteria $a */
                            /** @var CorrectionCriteria $b */
                            return ($a->getCriteria()->getOrder() < $b->getCriteria()->getOrder() ? -1 : 1);
                        });
                        $correctionCriterias = new ArrayCollection(iterator_to_array($iterator));
                        /** @var CorrectionCriteria $correctionCriteria */
                        foreach ($correctionCriterias as $correctionCriteria) {
                            $columnPrefix = 'Section ' . $sectionIndex . ' - Critère ' . $criteriaIndex . ' - ';
                            $line[utf8_decode($columnPrefix . 'commentaires')] =
                                $draft ? null : htmlspecialchars_decode(strip_tags(utf8_decode($correctionCriteria->getComments())));
                            $line[utf8_decode($columnPrefix . 'note')] =
                                $draft ? null : $this->formatNumber($correctionCriteria->getMark());
                            $line[utf8_decode($columnPrefix . 'fiabilité')] =
                                $draft ? null : $this->formatNumber($correctionCriteria->getReliability());
                            /** @var AssignmentCriteria $assignmentCriteria */
                            foreach ($correctionCriteria->getCriteria()->getAssignmentCriterias() as $assignmentCriteria) {
                                if ($assignmentCriteria->getAssignmentSection()->getAssignment() === $assignment) {
                                    $line[utf8_decode($columnPrefix . 'moyenne brute')] =
                                        $this->formatNumber($assignmentCriteria->getRawMark());
                                    $line[utf8_decode($columnPrefix . 'écart-type')] =
                                        $this->formatNumber($assignmentCriteria->getStandardDeviation());
                                    $line[utf8_decode($columnPrefix . 'moyenne pondérée')] =
                                        $this->formatNumber($assignmentCriteria->getWeightedMark());
                                    $line[utf8_decode($columnPrefix . 'fiabilité moyenne')] =
                                        $this->formatNumber($assignmentCriteria->getReliability());
                                    break;
                                }
                            }
                            if (isset($correctionTeacher)) {
                                foreach ($correctionTeacher->getCorrectionSections() as $correctionSectionTeacher) {
                                    /** @var CorrectionCriteria $correctionCriteriaTeacher */
                                    foreach ($correctionSectionTeacher->getCorrectionCriterias() as $correctionCriteriaTeacher) {
                                        if ($correctionCriteriaTeacher->getCriteria() === $correctionCriteria->getCriteria()) {
                                            $line[utf8_decode($columnPrefix . 'commentaires enseignant')] =
                                                htmlspecialchars_decode(strip_tags(utf8_decode($correctionCriteriaTeacher->getComments())));
                                            $line[utf8_decode($columnPrefix . 'note enseignant')] =
                                                $this->formatNumber($correctionCriteriaTeacher->getMark());
                                            break 2;
                                        }
                                    }
                                }
                            } else {
                                $line[utf8_decode($columnPrefix . 'note enseignant')] = null;
                            }
                            $line[utf8_decode($columnPrefix . 'fiabilité recalculée')] =
                                $draft ? null : $this->formatNumber($correctionCriteria->getRecalculatedReliability());
                            $line[utf8_decode($columnPrefix . 'note finale')] =
                                $this->formatNumber($assignmentCriteria->getMark());
                            $criteriaIndex++;
                        }
                        $sectionIndex++;
                    }
                    $line['Note de la correction'] = $draft ? null : $this->formatNumber($correction->getMark());
                    $line[utf8_decode('Fiabilité de la correction')] = $draft ? null : $this->formatNumber($correction->getReliability());
                    $line['Moyenne brute du devoir'] = $this->formatNumber($assignment->getRawMark());
                    $line['Ecart-type'] = $this->formatNumber($assignment->getStandardDeviation());
                    $line[utf8_decode('Moyenne pondérée du devoir')] = $this->formatNumber($assignment->getWeightedMark());
                    $line[utf8_decode('Fiabilité moyenne des corrections')] = $this->formatNumber($assignment->getReliability());
                    $line['Note enseignant'] = isset($correctionTeacher) ? $this->formatNumber($correctionTeacher->getMark()) : null;
                    $line[utf8_decode('Fiabilité recalculée')] = $this->formatNumber($correction->getRecalculatedReliability());
                    $line['Note finale'] = $this->formatNumber($assignment->getMark());

                    $table[] = $line;
                }
            }
        }

        $serializer = new Serializer([new ObjectNormalizer()], [new CsvEncoder(";")]);
        $csv = $serializer->encode($table, 'csv');
        return $csv;
    }

    /**
     * @param $value
     * @return null|string
     */
    private function formatNumber($value)
    {
        if (is_numeric($value)) {
            return number_format($value, 2, ',', '');
        } else {
            return null;
        }
    }

    /**
     * @param Evaluation $evaluation
     * @return \Symfony\Component\Serializer\Encoder\scalar
     */
    private function buildQualityCsv(Evaluation $evaluation)
    {
        /** @var StatsService $statsService */
        $statsService = $this->container->get('app.services.stats');

        /** @var array $quality */
        $quality = $statsService->getQualityStats($evaluation);

        $table = array();

        foreach ($quality as $item) {
            $line = array();
            if ($evaluation->getIndividualCorrection()) {
                /** @var User $user */
                $user = $item['user'];
                $line['Correcteur'] = utf8_decode($user->getLastName() . ' ' . $user->getFirstName());
            } else {
                /** @var Group $group */
                $group = $item['group'];
                $line['Correcteur'] = utf8_decode($group->getName());
            }
            $index = 1;
            foreach ($item['criterias_reliability'] as $reliability) {
                $line[utf8_decode('Critère ' . $index . ' fiabilité')] = $this->formatNumber($reliability);
                $index++;
            }
            $line[utf8_decode('Fiabilité moyenne')] = $this->formatNumber($item['average_reliability']);
            $table[] = $line;
        }

        $serializer = new Serializer([new ObjectNormalizer()], [new CsvEncoder(";")]);
        $csv = $serializer->encode($table, 'csv');
        return $csv;
    }

    /**
     * @param string $zipName
     * @param Evaluation $evaluation
     * @return ZipArchive
     */
    public function buildCompleteZip(string $zipName, Evaluation $evaluation)
    {
        $tempDirectory = $this->container->getParameter('upload.directory') . '/temp_evaluation_' . $evaluation->getId() . '/';
        if (!file_exists($tempDirectory)) {
            mkdir($tempDirectory);
        }

        // ZIP
        $zip = new ZipArchive;
        $zip->open($zipName, ZipArchive::CREATE);

        // Add evaluation subject
        $this->addEvaluation($zip, $evaluation);

        $zip->addEmptyDir('Statistiques');

        $csvContent = $this->buildCriteriaStatsCsv($evaluation);
        $csvName = sprintf("Export_evaluation_stats_%d_%s.csv", $evaluation->getId(), date("Ymd_his"));
        $zip->addFromString('Statistiques/' . $csvName, $csvContent);

        $csvQuality = $this->buildQualityCsv($evaluation);
        $csvName = sprintf("Export_evaluation_quality_%d_%s.csv", $evaluation->getId(), date("Ymd_his"));
        $zip->addFromString('Statistiques/' . $csvName, $csvQuality);


        $zip->addEmptyDir('Devoirs');

        /** @var Assignment $assignment */
        foreach ($evaluation->getAssignments() as $assignment) {
            if (!$assignment->isDraft()) {
                $this->addAssignment($zip, $assignment);
            }
        }

        $zip->close();

        $this->delete_files($tempDirectory);

        return $zip;
    }

    /**
     * @param ZipArchive $zip
     * @param Evaluation $evaluation
     */
    private function addEvaluation(ZipArchive $zip, Evaluation $evaluation)
    {
        // Sort sections
        $sections = $evaluation->getSections();
        $iterator = $sections->getIterator();
        $iterator->uasort(function ($sectionA, $sectionB) {
            /** @var Section $sectionA */
            /** @var Section $sectionB */
            return ($sectionA->getOrder() < $sectionB->getOrder() ? -1 : 1);
        });
        $sections = new ArrayCollection(iterator_to_array($iterator));

        /** @var Section $section */
        foreach ($sections as $section) {
            // Sort criterias
            $criterias = $section->getCriterias();
            $iterator = $criterias->getIterator();
            $iterator->uasort(function ($criteriaA, $criteriaB) {
                /** @var Criteria $criteriaA */
                /** @var Criteria $criteriaB */
                return ($criteriaA->getOrder() < $criteriaB->getOrder() ? -1 : 1);
            });
            $criterias = new ArrayCollection(iterator_to_array($iterator));
            /** @var Criteria $criteria */
            foreach ($section->getCriterias() as $criteria) {
                $section->removeCriteria($criteria);
            }
            foreach ($criterias as $criteria) {
                $section->addCriteria($criteria);
            }
        }

        $tempDirectory = $this->container->getParameter('upload.directory') . '/temp_evaluation_' . $evaluation->getId() . '/';

        /** @var FormatService $formatService */
        $formatService = $this->container->get('app.service.format_service');
        $formattedName = urlencode($formatService->strflat(str_replace(' ', '_', $evaluation->getName())));
        $fileName = $formattedName . '.html';

        $file = fopen($tempDirectory . $fileName, 'w');

        fwrite($file, '<h1>Evaluation : ' . $evaluation->getName() . '</h1>');
        fwrite($file, '<h2>Cours : ' . $evaluation->getLesson()->getName() . '</h2>');
        fwrite($file, '<h2>Créée par ' . $evaluation->getTeacher()->getLastName() . ' ' . $evaluation->getTeacher()->getFirstName() . '</h2>');

        if ($evaluation->getIndividualAssignment()) {
            fwrite($file, '<p>Devoir individuel' . '</p>');
        } else {
            fwrite($file, '<p>Devoir de groupe' . '</p>');
        }

        if ($evaluation->getIndividualCorrection()) {
            fwrite($file, '<p>Correction individuelle' . '</p>');
        } else {
            fwrite($file, '<p>Correction de groupe' . '</p>');
        }

        if ($evaluation->getDateEndAssignment()) {
            fwrite($file, '<p>Devoir à rendre entre le ' .
                $evaluation->getDateStartAssignment()->format('d/m/Y H:i:s') . ' et le ' .
                $evaluation->getDateEndAssignment()->format('d/m/Y H:i:s') . '</p>');
        }
        if ($evaluation->getDateEndCorrection()) {
            fwrite($file, '<p>Correction à rendre entre le ' .
                $evaluation->getDateStartCorrection()->format('d/m/Y H:i:s') . ' et le ' .
                $evaluation->getDateEndCorrection()->format('d/m/Y H:i:s') . '</p>');
        }
        if ($evaluation->getDateEndOpinion()) {
            fwrite($file, '<p>Opinion à rendre avant le ' .
                $evaluation->getDateEndOpinion()->format('d/m/Y H:i:s') . '</p>');
        }

        fwrite($file, '<h3>Sujet :</h3>');
        fwrite($file, $evaluation->getSubject());

        fwrite($file, '<h3>Consignes :</h3>');
        fwrite($file, $evaluation->getAssignmentInstructions());

        fwrite($file, '<h3>Consignes de correction :</h3>');
        fwrite($file, $evaluation->getCorrectionInstructions());

        foreach ($sections as $section) {
            fwrite($file, '<h3>Section ' . $section->getOrder() . ' - ');
            fwrite($file, $section->getTitle() . ' : ' . $section->getSectionType()->getLabel() . '</h3>');
            fwrite($file, $section->getSubject());
            foreach ($section->getCriterias() as $criteria) {
                fwrite($file, '<h4>Critère ' . $criteria->getOrder() . ' : ' . $criteria->getCriteriaType()->getType() . '</h4>');
                fwrite($file, $criteria->getDescription());
                switch ($criteria->getCriteriaType()->getId()) {
                    case Constants::CRITERIA_TYPE_CHOICE:
                        /** @var CriteriaChoice $criteriaChoice */
                        foreach ($criteria->getCriteriaChoices() as $criteriaChoice) {
                            fwrite($file, '<p>' . $criteriaChoice->getName() . ' : ' . $criteriaChoice->getMark() . '</p>');
                        }
                        break;
                    case Constants::CRITERIA_TYPE_JUDGMENT:
                        fwrite($file, '<p>Note minimum : ' . $criteria->getMarkMin() . ', note maximum : ' .
                            $criteria->getMarkMax() . ', précision : ' . $criteria->getPrecision() . '</p>');
                        break;
                }
            }
        }

        $zip->addFile($tempDirectory . $fileName, $fileName);

        fclose($file);

        $this->addSubjectFiles($zip, $evaluation);

        $this->addExampleFiles($zip, $evaluation);
    }

    /**
     * @param ZipArchive $zip
     * @param Evaluation $evaluation
     */
    private function addSubjectFiles(ZipArchive $zip, Evaluation $evaluation)
    {
        if (count($evaluation->getSubjectFiles()) > 0) {
            $zip->addEmptyDir('Fichiers_sujets');

            $uploadDirectory = $this->container->getParameter('upload.directory');
            $directory = $uploadDirectory . sprintf(
                    Constants::EVALUATION_SUBJECT_FILE_PATH_FORMAT,
                    $evaluation->getId()
                );
            /** @var SubjectFile $subjectFile */
            foreach ($evaluation->getSubjectFiles() as $subjectFile) {
                $filePath = $directory . $subjectFile->getFileName();
                $zip->addFile($filePath, 'Fichiers_sujets/' . $subjectFile->getFileName());
            }
        }
    }

    /**
     * @param ZipArchive $zip
     * @param Evaluation $evaluation
     */
    private function addExampleFiles(ZipArchive $zip, Evaluation $evaluation)
    {
        if (count($evaluation->getExampleAssignments()) > 0) {
            $zip->addEmptyDir('Devoirs_exemples');

            $uploadDirectory = $this->container->getParameter('upload.directory');
            $directory = $uploadDirectory . sprintf(
                    Constants::EVALUATION_EXAMPLE_ASSIGNMENT_FILE_PATH_FORMAT,
                    $evaluation->getId()
                );
            /** @var ExampleAssignment $exampleAssignment */
            foreach ($evaluation->getExampleAssignments() as $exampleAssignment) {
                $filePath = $directory . $exampleAssignment->getFileName();
                $zip->addFile($filePath, 'Devoirs_exemples/' . $exampleAssignment->getFileName());
            }
        }
    }

    /**
     * @param ZipArchive $zip
     * @param Assignment $assignment
     */
    private function addAssignment(ZipArchive $zip, Assignment $assignment)
    {
        // Sort assignment sections
        $assignmentSections = $assignment->getAssignmentSections();
        $iterator = $assignmentSections->getIterator();
        $iterator->uasort(function ($a, $b) {
            /** @var AssignmentSection $a */
            /** @var AssignmentSection $b */
            /** @var Section $sectionA */
            $sectionA = $a->getSection();
            /** @var Section $sectionB */
            $sectionB = $b->getSection();
            return ($sectionA->getOrder() < $sectionB->getOrder() ? -1 : 1);
        });
        $assignmentSections = new ArrayCollection(iterator_to_array($iterator));

        /** @var FormatService $formatService */
        $formatService = $this->container->get('app.service.format_service');
        $formattedName = urlencode($formatService->strflat(str_replace(' ', '_', $assignment->getAuthorName())));

        $localDirName = 'Devoirs/' . $assignment->getId() . '_' . $formattedName;
        $zip->addEmptyDir($localDirName);

        $fileName = $formattedName . '.html';

        $targetDirectory = $this->container->getParameter('upload.directory') . '/temp_evaluation_' . $assignment->getEvaluation()->getId() . '/';
        $file = fopen($targetDirectory . $fileName, 'w');

        if ($assignment->getUser()) {
            fwrite($file, '<h1>Devoir de ' . $assignment->getAuthorName() . '</h1>');
        } else {
            fwrite($file, '<h1>Devoir du groupe ' . $assignment->getAuthorName() . '</h1>');
        }

        fwrite($file, '<h2>Rendu le ' . $assignment->getDateSubmission()->format('d/m/Y H:i:s') . '</h2>');

        /** @var AssignmentSection $assignmentSection */
        foreach ($assignmentSections as $assignmentSection) {
            fwrite($file, '<h3>Section ' . $assignmentSection->getSection()->getOrder() . ' - ');
            fwrite($file, $assignmentSection->getSection()->getTitle() . '</h3>');

            switch ($assignmentSection->getSection()->getSectionType()->getId()) {
                case Constants::SECTION_TYPE_TEXT:
                    fwrite($file, $assignmentSection->getAnswer());
                    break;
                case Constants::SECTION_TYPE_FILE:
                    $answerFileName = 'Section_' . $assignmentSection->getSection()->getOrder() . '_' . $assignmentSection->getAnswer();
                    fwrite($file, '<p>Fichier : ' . $answerFileName . '</p>');

                    $uploadedFileName = $this->container->getParameter('upload.directory') . sprintf(
                            Constants::ASSIGNMENT_SECTION_FILE_PATH_FORMAT,
                            $assignment->getId(),
                            $assignmentSection->getId()
                        ) . $assignmentSection->getAnswer();

                    if(file_exists($uploadedFileName) && is_file($uploadedFileName)){
                        $zip->addFile($uploadedFileName, $localDirName . '/' . $answerFileName);
                    }
                    break;
                case Constants::SECTION_TYPE_URL:
                    fwrite($file, '<p><a href=' . $assignmentSection->getAnswer() . '>' . $assignmentSection->getAnswer() . '</a></p>');
                    break;
            }
        }

        $zip->addFile($targetDirectory . $fileName, $localDirName . '/' . $fileName);

        fclose($file);

        $zip->addEmptyDir($localDirName . '/Corrections');

        /** @var Correction $correction */
        foreach ($assignment->getCorrections() as $correction) {
            if (!$correction->isDraft()) {
                $this->addCorrection($zip, $correction, $localDirName . '/Corrections');
            }
        }
    }

    /**
     * @param ZipArchive $zip
     * @param Correction $correction
     * @param string $dirName
     */
    private function addCorrection(ZipArchive $zip, Correction $correction, string $dirName)
    {
        // Sort correctionSections by section order
        /** @var ArrayCollection $correctionSections */
        $correctionSections = $correction->getCorrectionSections();
        $iterator = $correctionSections->getIterator();
        $iterator->uasort(function ($a, $b) {
            /** @var CorrectionSection $a */
            /** @var CorrectionSection $b */
            /** @var Section $sectionA */
            $sectionA = $a->getAssignmentSection()->getSection();
            /** @var Section $sectionB */
            $sectionB = $b->getAssignmentSection()->getSection();
            return ($sectionA->getOrder() < $sectionB->getOrder() ? -1 : 1);
        });
        $correctionSections = new ArrayCollection(iterator_to_array($iterator));
        /** @var CorrectionSection $correctionSection */
        foreach ($correctionSections as $correctionSection) {
            // Sort correctionCriterias by criteria order
            $correctionCriterias = $correctionSection->getCorrectionCriterias();
            $iterator = $correctionCriterias->getIterator();
            $iterator->uasort(function ($a, $b) {
                /** @var CorrectionCriteria $a */
                /** @var CorrectionCriteria $b */
                return ($a->getCriteria()->getOrder() < $b->getCriteria()->getOrder() ? -1 : 1);
            });
            $correctionCriterias = new ArrayCollection(iterator_to_array($iterator));
            $correctionSection->removeCorrectionCriterias();
            /** @var CorrectionCriteria $correctionCriteria */
            foreach ($correctionCriterias as $correctionCriteria) {
                $correctionSection->addCorrectionCriteria($correctionCriteria);
            }
        }

        /** @var FormatService $formatService */
        $formatService = $this->container->get('app.service.format_service');
        $formattedName = urlencode($formatService->strflat(str_replace(' ', '_', $correction->getAuthorName())));
        $fileName = $correction->getId() . '_' . $formattedName . '.html';

        $targetDirectory = $this->container->getParameter('upload.directory') . '/temp_evaluation_' . $correction->getAssignment()->getEvaluation()->getId() . '/corrections/';
        if (!file_exists($targetDirectory)) {
            mkdir($targetDirectory);
        }
        $file = fopen($targetDirectory . $fileName, 'w');

        if ($correction->getAssignment()->getUser()) {
            $assignmentName = 'devoir de ' . $correction->getAssignment()->getAuthorName();
        } else {
            $assignmentName = 'devoir du groupe ' . $correction->getAssignment()->getAuthorName();
        }

        if ($correction->getUser()) {
            fwrite($file, '<h1>Correction du ' . $assignmentName . ' par ' . $correction->getAuthorName() . '</h1>');
        } else {
            fwrite($file, '<h1>Correction du ' . $assignmentName . ' par le groupe ' . $correction->getAuthorName() . '</h1>');
        }

        fwrite($file, '<h2>Correction rendue le ' . $correction->getDateSubmission()->format('d/m/Y H:i:s') . '</h2>');

        fwrite($file, '<p>Note : ' . $correction->getMark() . ', fiabilité : ' . $correction->getReliability() . '</p>');

        foreach ($correctionSections as $correctionSection) {
            /** @var Section $section */
            $section = $correctionSection->getAssignmentSection()->getSection();
            fwrite($file, '<h3>Section ' . $section->getOrder() . ' - ' . $section->getTitle() . '</h3>');
            foreach ($correctionSection->getCorrectionCriterias() as $correctionCriteria) {
                /** @var Criteria $criteria */
                $criteria = $correctionCriteria->getCriteria();
                fwrite($file, '<h4>Critère ' . $criteria->getOrder() . ' - ' . $criteria->getDescription() . '</h4>');
                switch ($criteria->getCriteriaType()->getId()) {
                    case Constants::CRITERIA_TYPE_COMMENT:
                        fwrite($file, $correctionCriteria->getComments());
                        break;
                    case Constants::CRITERIA_TYPE_CHOICE:
                        /** @var CriteriaChoice $criteriaChoice */
                        foreach ($criteria->getCriteriaChoices() as $criteriaChoice) {
                            if ($correctionCriteria->getMark() === $criteriaChoice->getMark()) {
                                fwrite($file, '<p><strong>' . $criteriaChoice->getName() . ' : ' . $criteriaChoice->getMark() . '</strong></p>');
                            } else {
                                fwrite($file, '<p>' . $criteriaChoice->getName() . ' : ' . $criteriaChoice->getMark() . '</p>');
                            }
                        }
                        break;
                    case Constants::CRITERIA_TYPE_JUDGMENT:
                        fwrite($file, '<p>Note : ' . $correctionCriteria->getMark() . '/' . $criteria->getMarkMax() . '</p>');
                        fwrite($file, $correctionCriteria->getComments());
                        break;
                }
                /** @var CorrectionOpinion $correctionOpinion */
                $correctionOpinion = $correctionCriteria->getCorrectionOpinion();
                fwrite($file, '<p>Avis : ');
                switch ($correctionOpinion->getOpinion()) {
                    case -1:
                        fwrite($file, '<strong>Négatif</strong>');
                        break;
                    case 0:
                        fwrite($file, 'Neutre');
                        break;
                    case 1:
                        fwrite($file, '<strong>Positif</strong>');
                        break;
                }
                fwrite($file, '</p>');
                fwrite($file, $correctionOpinion->getComments());
            }
        }

        $zip->addFile($targetDirectory . $fileName, $dirName . '/' . $fileName);

        fclose($file);
    }

    private function delete_files($target)
    {
        if (is_dir($target)) {
            $files = glob($target . '*', GLOB_MARK); //GLOB_MARK adds a slash to directories returned

            foreach ($files as $file) {
                $this->delete_files($file);
            }

            rmdir($target);
        } elseif (is_file($target)) {
            unlink($target);
        }
    }
}