<?php

namespace Truonglv\ResourceUpdateMassive\Job;

use XF\Timer;
use XF\Util\File;
use XF\FileWrapper;
use XF\Job\JobResult;
use XF\Job\AbstractJob;
use XF\Repository\Attachment;
use XFRM\Entity\ResourceItem;
use XFRM\Entity\ResourceUpdate;
use XF\Service\Attachment\Preparer;
use XF\Mvc\Entity\AbstractCollection;
use XFRM\Service\ResourceUpdate\Edit;

class Update extends AbstractJob
{
    /**
     * @var array
     */
    protected $defaultData = [
        'resourceId' => 0,
        'attachmentHash' => '',
        'lastUpdateId' => 0
    ];

    /**
     * @inheritDoc
     * @param mixed $maxRunTime
     * @return JobResult
     */
    public function run($maxRunTime)
    {
        /** @var ResourceItem|null $resource */
        $resource = $this->app->em()->find('XFRM:ResourceItem', $this->data['resourceId']);
        if (!$resource) {
            return $this->complete();
        }

        $attachmentHash = trim($this->data['attachmentHash']);
        if (strlen($attachmentHash) !== 32) {
            return $this->complete();
        }

        $attachments = $this->app->finder('XF:Attachment')
            ->with('Data')
            ->where('temp_hash', $attachmentHash)
            ->fetch();
        if ($attachments->count() === 0) {
            return $this->complete();
        }

        $updates = $this->app->finder('XFRM:ResourceUpdate')
            ->where('resource_id', $resource->resource_id)
            ->where('resource_update_id', '>', $this->data['lastUpdateId'])
            ->order('resource_update_id')
            ->limit(20)
            ->fetch();
        if ($updates->count() === 0) {
            return $this->complete();
        }

        $timer = $maxRunTime > 0 ? new Timer($maxRunTime) : null;
        /** @var ResourceUpdate $update */
        foreach ($updates as $update) {
            $this->data['lastUpdateId'] = $update->resource_update_id;

            $this->doUpdate($update, $attachments);

            if ($timer !== null && $timer->limitExceeded()) {
                break;
            }
        }

        return $this->resume();
    }

    /**
     * @param ResourceUpdate $update
     * @param AbstractCollection $attachments
     * @return void
     */
    protected function doUpdate(ResourceUpdate $update, AbstractCollection $attachments)
    {
        /** @var Preparer $preparer */
        $preparer = $this->app->service('XF:Attachment\Preparer');
        /** @var Attachment $attachmentRepo */
        $attachmentRepo = $this->app->repository('XF:Attachment');

        $handler = $attachmentRepo->getAttachmentHandler('resource_update');

        $tempHash = md5(uniqid('', true));
        /** @var \XF\Entity\Attachment $attachment */
        foreach ($attachments as $attachment) {
            $tempFile = File::copyAbstractedPathToTempFile($attachment->Data->getAbstractedDataPath());
            $file = new FileWrapper(
                $tempFile,
                $attachment->filename
            );

            $preparer->insertAttachment(
                $handler,
                $file,
                $update->Resource->User,
                $tempHash
            );
        }

        /** @var Edit $editor */
        $editor = $this->app->service('XFRM:ResourceUpdate\Edit', $update);
        $editor->setAttachmentHash($tempHash);
        if (!$editor->validate($errors)) {
            return;
        }

        $editor->save();
    }

    /**
     * @return \XF\Phrase
     */
    public function getStatusMessage()
    {
        return \XF::phrase('xfrm_resources');
    }

    /**
     * @return bool
     */
    public function canCancel()
    {
        return false;
    }

    /**
     * @return bool
     */
    public function canTriggerByChoice()
    {
        return false;
    }
}
