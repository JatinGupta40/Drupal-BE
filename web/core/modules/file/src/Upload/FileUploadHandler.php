<?php

namespace Drupal\file\Upload;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\Event\FileUploadSanitizeNameEvent;
use Drupal\Core\File\Exception\FileExistsException;
use Drupal\Core\File\Exception\FileWriteException;
use Drupal\Core\File\Exception\InvalidStreamWrapperException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\Validation\FileValidatorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\Exception\CannotWriteFileException;
use Symfony\Component\HttpFoundation\File\Exception\ExtensionFileException;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\Exception\FormSizeFileException;
use Symfony\Component\HttpFoundation\File\Exception\IniSizeFileException;
use Symfony\Component\HttpFoundation\File\Exception\NoFileException;
use Symfony\Component\HttpFoundation\File\Exception\NoTmpDirFileException;
use Symfony\Component\HttpFoundation\File\Exception\PartialFileException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

/**
 * Handles validating and creating file entities from file uploads.
 */
class FileUploadHandler {

  /**
   * The default extensions if none are provided.
   */
  const DEFAULT_EXTENSIONS = 'jpg jpeg gif png txt doc xls pdf ppt pps odt ods odp';

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The MIME type guesser.
   *
   * @var \Symfony\Component\Mime\MimeTypeGuesserInterface
   */
  protected $mimeTypeGuesser;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The file Repository.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected $fileRepository;

  /**
   * The file validator.
   *
   * @var \Drupal\file\Validation\FileValidatorInterface
   */
  protected FileValidatorInterface $fileValidator;

  /**
   * Constructs a FileUploadHandler object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Symfony\Component\Mime\MimeTypeGuesserInterface $mimeTypeGuesser
   *   The MIME type guesser.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\file\FileRepositoryInterface|null $fileRepository
   *   The file repository.
   * @param \Drupal\file\Validation\FileValidatorInterface|null $file_validator
   *   The file validator.
   */
  public function __construct(FileSystemInterface $fileSystem, EntityTypeManagerInterface $entityTypeManager, StreamWrapperManagerInterface $streamWrapperManager, EventDispatcherInterface $eventDispatcher, MimeTypeGuesserInterface $mimeTypeGuesser, AccountInterface $currentUser, RequestStack $requestStack, FileRepositoryInterface $fileRepository = NULL, FileValidatorInterface $file_validator = NULL) {
    $this->fileSystem = $fileSystem;
    $this->entityTypeManager = $entityTypeManager;
    $this->streamWrapperManager = $streamWrapperManager;
    $this->eventDispatcher = $eventDispatcher;
    $this->mimeTypeGuesser = $mimeTypeGuesser;
    $this->currentUser = $currentUser;
    $this->requestStack = $requestStack;
    if ($fileRepository === NULL) {
      @trigger_error('Calling ' . __METHOD__ . ' without the $fileRepository argument is deprecated in drupal:10.1.0 and will be required in drupal:11.0.0. See https://www.drupal.org/node/3346839', E_USER_DEPRECATED);
      $fileRepository = \Drupal::service('file.repository');
    }
    $this->fileRepository = $fileRepository;
    if (!$file_validator) {
      @trigger_error('Calling ' . __METHOD__ . '() without the $file_validator argument is deprecated in drupal:10.2.0 and is required in drupal:11.0.0. See https://www.drupal.org/node/3363700', E_USER_DEPRECATED);
      $file_validator = \Drupal::service('file.validator');
    }
    $this->fileValidator = $file_validator;
  }

  /**
   * Creates a file from an upload.
   *
   * @param \Drupal\file\Upload\UploadedFileInterface $uploadedFile
   *   The uploaded file object.
   * @param array $validators
   *   The validators to run against the uploaded file.
   * @param string $destination
   *   The destination directory.
   * @param int $replace
   *   Replace behavior when the destination file already exists:
   *   - FileSystemInterface::EXISTS_REPLACE - Replace the existing file.
   *   - FileSystemInterface::EXISTS_RENAME - Append _{incrementing number}
   *     until the filename is unique.
   *   - FileSystemInterface::EXISTS_ERROR - Throw an exception.
   * @param bool $throw
   *   (optional) Whether to throw an exception if the file is invalid.
   *
   * @return \Drupal\file\Upload\FileUploadResult
   *   The created file entity.
   *
   * @throws \Symfony\Component\HttpFoundation\File\Exception\FileException
   *    Thrown when a file upload error occurred and $throws is TRUE.
   * @throws \Drupal\Core\File\Exception\FileWriteException
   *    Thrown when there is an error moving the file and $throws is TRUE.
   * @throws \Drupal\Core\File\Exception\FileException
   *    Thrown when a file system error occurs and $throws is TRUE.
   * @throws \Drupal\file\Upload\FileValidationException
   *    Thrown when file validation fails and $throws is TRUE.
   */
  public function handleFileUpload(UploadedFileInterface $uploadedFile, array $validators = [], string $destination = 'temporary://', int $replace = FileSystemInterface::EXISTS_REPLACE, bool $throw = TRUE): FileUploadResult {
    $originalName = $uploadedFile->getClientOriginalName();
    // @phpstan-ignore-next-line
    if ($throw && !$uploadedFile->isValid()) {
      @trigger_error('Calling ' . __METHOD__ . '() with the $throw argument as TRUE is deprecated in drupal:10.3.0 and will be removed in drupal:11.0.0. Use \Drupal\file\Upload\FileUploadResult::getViolations() instead. See https://www.drupal.org/node/3375456', E_USER_DEPRECATED);
      // @phpstan-ignore-next-line
      switch ($uploadedFile->getError()) {
        case \UPLOAD_ERR_INI_SIZE:
          // @phpstan-ignore-next-line
          throw new IniSizeFileException($uploadedFile->getErrorMessage());

        case \UPLOAD_ERR_FORM_SIZE:
          // @phpstan-ignore-next-line
          throw new FormSizeFileException($uploadedFile->getErrorMessage());

        case \UPLOAD_ERR_PARTIAL:
          // @phpstan-ignore-next-line
          throw new PartialFileException($uploadedFile->getErrorMessage());

        case \UPLOAD_ERR_NO_FILE:
          // @phpstan-ignore-next-line
          throw new NoFileException($uploadedFile->getErrorMessage());

        case \UPLOAD_ERR_CANT_WRITE:
          // @phpstan-ignore-next-line
          throw new CannotWriteFileException($uploadedFile->getErrorMessage());

        case \UPLOAD_ERR_NO_TMP_DIR:
          // @phpstan-ignore-next-line
          throw new NoTmpDirFileException($uploadedFile->getErrorMessage());

        case \UPLOAD_ERR_EXTENSION:
          // @phpstan-ignore-next-line
          throw new ExtensionFileException($uploadedFile->getErrorMessage());

      }
      // @phpstan-ignore-next-line
      throw new FileException($uploadedFile->getErrorMessage());
    }

    $extensions = $this->handleExtensionValidation($validators);

    // Assert that the destination contains a valid stream.
    $destinationScheme = $this->streamWrapperManager::getScheme($destination);
    if (!$this->streamWrapperManager->isValidScheme($destinationScheme)) {
      throw new InvalidStreamWrapperException(sprintf('The file could not be uploaded because the destination "%s" is invalid.', $destination));
    }

    // A file URI may already have a trailing slash or look like "public://".
    if (!str_ends_with($destination, '/')) {
      $destination .= '/';
    }

    // Call an event to sanitize the filename and to attempt to address security
    // issues caused by common server setups.
    $event = new FileUploadSanitizeNameEvent($originalName, $extensions);
    $this->eventDispatcher->dispatch($event);
    $filename = $event->getFilename();

    $mimeType = $this->mimeTypeGuesser->guessMimeType($filename);
    $destinationFilename = $this->fileSystem->getDestinationFilename($destination . $filename, $replace);
    if ($destinationFilename === FALSE) {
      throw new FileExistsException(sprintf('Destination file "%s" exists', $destinationFilename));
    }

    $file = File::create([
      'uid' => $this->currentUser->id(),
      'status' => 0,
      'uri' => $uploadedFile->getRealPath(),
    ]);

    // This will be replaced later with a filename based on the destination.
    $file->setFilename($filename);
    $file->setMimeType($mimeType);
    $file->setSize($uploadedFile->getSize());

    // Add in our check of the file name length.
    $validators['FileNameLength'] = [];

    $result = new FileUploadResult();

    // Call the validation functions specified by this function's caller.
    $violations = $this->fileValidator->validate($file, $validators);
    if (count($violations) > 0) {
      $result->addViolations($violations);
      return $result;
    }

    if ($throw) {
      $errors = [];
      foreach ($violations as $violation) {
        $errors[] = $violation->getMessage();
      }
      if (!empty($errors)) {
        throw new FileValidationException('File validation failed', $filename, $errors);
      }
    }

    $file->setFileUri($destinationFilename);

    if (!$this->moveUploadedFile($uploadedFile, $file->getFileUri())) {
      throw new FileWriteException('File upload error. Could not move uploaded file.');
    }

    // Update the filename with any changes as a result of security or renaming
    // due to an existing file.
    $file->setFilename($this->fileSystem->basename($file->getFileUri()));

    if ($replace === FileSystemInterface::EXISTS_REPLACE) {
      $existingFile = $this->fileRepository->loadByUri($file->getFileUri());
      if ($existingFile) {
        $file->fid = $existingFile->id();
        $file->setOriginalId($existingFile->id());
      }
    }

    $result->setOriginalFilename($originalName)
      ->setSanitizedFilename($filename)
      ->setFile($file);

    // If the filename has been modified, let the user know.
    if ($event->isSecurityRename()) {
      $result->setSecurityRename();
    }

    // Set the permissions on the new file.
    $this->fileSystem->chmod($file->getFileUri());

    // We can now validate the file object itself before it's saved.
    $violations = $file->validate();
    if ($throw) {
      foreach ($violations as $violation) {
        $errors[] = $violation->getMessage();
      }
      if (!empty($errors)) {
        throw new FileValidationException('File validation failed', $filename, $errors);
      }
    }
    if (count($violations) > 0) {
      $result->addViolations($violations);
      return $result;
    }

    // If we made it this far it's safe to record this file in the database.
    $file->save();

    // Allow an anonymous user who creates a non-public file to see it. See
    // \Drupal\file\FileAccessControlHandler::checkAccess().
    if ($this->currentUser->isAnonymous() && $destinationScheme !== 'public') {
      $session = $this->requestStack->getCurrentRequest()->getSession();
      $allowed_temp_files = $session->get('anonymous_allowed_file_ids', []);
      $allowed_temp_files[$file->id()] = $file->id();
      $session->set('anonymous_allowed_file_ids', $allowed_temp_files);
    }

    return $result;
  }

  /**
   * Move the uploaded file from the temporary path to the destination.
   *
   * @todo Allows a sub-class to override this method in order to handle
   * raw file uploads in https://www.drupal.org/project/drupal/issues/2940383.
   *
   * @param \Drupal\file\Upload\UploadedFileInterface $uploadedFile
   *   The uploaded file.
   * @param string $uri
   *   The destination URI.
   *
   * @return bool
   *   Returns FALSE if moving failed.
   *
   * @see https://www.drupal.org/project/drupal/issues/2940383
   */
  protected function moveUploadedFile(UploadedFileInterface $uploadedFile, string $uri) {
    return $this->fileSystem->moveUploadedFile($uploadedFile->getRealPath(), $uri);
  }

  /**
   * Gets the list of allowed extensions and updates the validators.
   *
   * This will add an extension validator to the list of validators if one is
   * not set.
   *
   * If the extension validator is set, but no extensions are specified, it
   * means all extensions are allowed, so the validator is removed from the list
   * of validators.
   *
   * @param array $validators
   *   The file validators in use.
   *
   * @return string
   *   The space delimited list of allowed file extensions.
   */
  protected function handleExtensionValidation(array &$validators): string {
    // Build a list of allowed extensions.
    if (isset($validators['FileExtension'])) {
      if (!isset($validators['FileExtension']['extensions'])) {
        // If 'FileExtension' is set and the list is empty then the caller wants
        // to allow any extension. In this case we have to remove the validator
        // or else it will reject all extensions.
        unset($validators['FileExtension']);
      }
    }
    else {
      if (!empty($validators['file_validate_extensions'][0])) {
        // The deprecated 'file_validate_extensions' has configuration, so that
        // should be used.
        $validators['FileExtension']['extensions'] = $validators['file_validate_extensions'][0];
        @trigger_error('\'file_validate_extensions\' is deprecated in drupal:10.2.0 and is removed from drupal:11.0.0. Use the \'FileExtension\' constraint instead. See https://www.drupal.org/node/3363700', E_USER_DEPRECATED);
        return $validators['FileExtension']['extensions'];
      }

      // No validator was provided, so add one using the default list.
      // Build a default non-munged safe list for
      // \Drupal\system\EventSubscriber\SecurityFileUploadEventSubscriber::sanitizeName().
      $validators['FileExtension'] = ['extensions' => self::DEFAULT_EXTENSIONS];

    }
    return $validators['FileExtension']['extensions'] ?? '';
  }

  /**
   * Loads the first File entity found with the specified URI.
   *
   * @param string $uri
   *   The file URI.
   *
   * @return \Drupal\file\FileInterface|null
   *   The first file with the matched URI if found, NULL otherwise.
   *
   * @deprecated in drupal:10.3.0 and is removed from drupal:11.0.0.
   *   Use \Drupal\file\FileRepositoryInterface::loadByUri().
   *
   * @see https://www.drupal.org/node/3409326
   */
  protected function loadByUri(string $uri): ?FileInterface {
    @trigger_error('FileUploadHandler::loadByUri() is deprecated in drupal:10.3.0 and is removed from drupal:11.0.0. Use \Drupal\file\FileRepositoryInterface::loadByUri(). See https://www.drupal.org/node/3409326', E_USER_DEPRECATED);
    return $this->fileRepository->loadByUri($uri);
  }

}
