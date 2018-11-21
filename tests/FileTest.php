<?php

use Office365\PHP\Client\Runtime\Utilities\Guid;
use Office365\PHP\Client\SharePoint\FileCreationInformation;

require_once('SharePointTestCase.php');


class FileTest extends SharePointTestCase
{
    /**
     * @var \Office365\PHP\Client\SharePoint\SPList
     */
    private static $targetList;



    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        $listTitle = "Documents_" . rand(1, 100000);
        self::$targetList = ListExtensions::ensureList(self::$context->getWeb(), $listTitle, \Office365\PHP\Client\SharePoint\ListTemplateType::DocumentLibrary);
    }

    public static function tearDownAfterClass()
    {
        self::$targetList->deleteObject();
        self::$context->executeQuery();
        parent::tearDownAfterClass();
    }


    public function testUploadFiles(){
        $localPath = __DIR__ . "/../examples/data/";
        $searchPrefix = $localPath . '*.*';
        $results = [];
        foreach(glob($searchPrefix) as $filename) {
            $fileCreationInformation = new \Office365\PHP\Client\SharePoint\FileCreationInformation();
            $fileCreationInformation->Content = file_get_contents($filename);
            $fileCreationInformation->Url = basename($filename);
            $fileCreationInformation->Overwrite = true;

            $uploadFile = self::$targetList->getRootFolder()->getFiles()->add($fileCreationInformation);
            self::$context->executeQuery();
            $this->assertEquals($uploadFile->getProperty("Name"),$fileCreationInformation->Url);
            $results[] = $uploadFile;
        }
        self::assertTrue(true);
        return $results[0];
    }

    public function testUploadLargeFile()
    {
        $uploadSessionId = Guid::newGuid();
        $localPath = __DIR__ . "/../examples/data/big_buck_bunny.mp4";
        $chunkSize = 1024 * 1024;
        $fileSize = filesize($localPath);
        $firstChunk = true;
        $handle = fopen($localPath, 'rb');
        $offset = 0;
        $fileCreationInformation = new FileCreationInformation();
        $fileCreationInformation->Url = "large_" . basename($localPath);
        $uploadFile = self::$targetList->getRootFolder()->getFiles()->add($fileCreationInformation);
        self::$context->executeQuery();

        while (!feof($handle)) {
            $buffer = fread($handle, $chunkSize);
            $bytesRead = ftell ( $handle );
            if ($firstChunk) {
                $resultOffset = $uploadFile->startUpload($uploadSessionId, $buffer);
                self::$context->executeQuery();
                $firstChunk = false;
            } elseif ($fileSize == $bytesRead) {
                $uploadFile = $uploadFile->finishUpload($uploadSessionId,$offset, $buffer);
                self::$context->executeQuery();
            } else {
                $resultOffset = $uploadFile->continueUpload($uploadSessionId,$offset, $buffer);
                self::$context->executeQuery();
            }
            $offset = $bytesRead;
        }
        fclose($handle);
        self::assertNotNull($uploadFile->getProperty("Name"));

    }

    /**
     * @depends testUploadFiles
     * @param $uploadFile
     */
    /*public function testUploadedFileCreateAnonymousLink(\Office365\PHP\Client\SharePoint\File $uploadFile)
    {
        $listItem = $uploadFile->getListItemAllFields();
        self::$context->load($listItem,array("EncodedAbsUrl"));
        self::$context->executeQuery();

        $fileUrl = $listItem->getProperty("EncodedAbsUrl");
        $result = Web::createAnonymousLink(self::$context,$fileUrl,false);
        self::$context->executeQuery();
        self::assertNotEmpty($result->Value);

        $expireDate = new \DateTime('now +1 day');
        $result = Web::createAnonymousLinkWithExpiration(self::$context,$fileUrl,false,$expireDate->format(DateTime::ATOM));
        self::$context->executeQuery();
        self::assertNotEmpty($result->Value);
    }*/

    public function testGetFileVersions()
    {
        $files = self::$targetList->getRootFolder()->getFiles()->select("Name,Version");
        self::$context->load($files);
        self::$context->executeQuery();
        $this->assertNotNull($files->getServerObjectIsNull());
    }

    public function testCreateFolder()
    {
        $folderName = "Archive_" . rand(1, 100000);
        $folder = self::$targetList->getRootFolder()->getFolders()->add($folderName);
        self::$context->load(self::$targetList->getRootFolder());
        self::$context->executeQuery();
        $expectedFolderUrl = self::$targetList->getRootFolder()->getProperty("ServerRelativeUrl") . "/" . $folderName;
        $this->assertEquals($folder->getProperty("ServerRelativeUrl"), $expectedFolderUrl);
        return $folder;
    }


    /**
     * @depends testCreateFolder
     * @param \Office365\PHP\Client\SharePoint\Folder $folderToRename
     * @return \Office365\PHP\Client\SharePoint\Folder
     */
    public function testRenameFolder(\Office365\PHP\Client\SharePoint\Folder $folderToRename)
    {
        $folderName = "2015";
        $folderToRename->rename($folderName);
        self::$context->executeQuery();

        self::$context->load(self::$targetList->getRootFolder());
        self::$context->executeQuery();
        $folderUrl = self::$targetList->getRootFolder()->getProperty("ServerRelativeUrl") . "/" . $folderName;
        $folder = self::$context->getWeb()->getFolderByServerRelativeUrl($folderUrl);
        self::$context->load($folder);
        self::$context->executeQuery();
        self::assertNotEmpty($folder->getProperties());
        return $folder;
    }


    /**
     * @depends testRenameFolder
     * @param \Office365\PHP\Client\SharePoint\Folder $folderToDelete
     */
    public function testDeleteFolder(\Office365\PHP\Client\SharePoint\Folder $folderToDelete)
    {
        $folderName = $folderToDelete->getProperty("Name");
        $folderToDelete->deleteObject();
        self::$context->executeQuery();


        $filterExpr = "FileLeafRef eq '$folderName'";
        $result = self::$targetList->getItems()->filter($filterExpr);
        self::$context->load($result);
        self::$context->executeQuery();
        self::assertEmpty($result->getCount());
    }

}
