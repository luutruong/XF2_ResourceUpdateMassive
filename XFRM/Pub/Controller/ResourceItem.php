<?php

namespace Truonglv\ResourceUpdateMassive\XFRM\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Repository\Attachment;

class ResourceItem extends XFCP_ResourceItem
{
    public function actionRUMUpdate(ParameterBag $params)
    {
        $resource = $this->assertViewableResource($params->resource_id);
        if (!$resource->canReleaseUpdate($error)) {
            return $this->noPermission($error);
        }

        if ($this->isPost()) {
            $this->rumUpdateMassive($resource);

            return $this->redirect($this->buildLink('resources', $resource));
        }

        $attachmentData = null;
        /** @var Attachment $attachmentRepo */
        $attachmentRepo = $this->repository('XF:Attachment');
        if ($resource->Category->canUploadAndManageUpdateImages()) {
            $attachmentData = $attachmentRepo->getEditorData(
                'resource_update',
                $resource
            );
        }

        return $this->view(
            'Truonglv\ResourceUpdateMassive:ResourceItem\UpdateMassive',
            'rum_resource_update_massive',
            compact('resource', 'attachmentData')
        );
    }

    /**
     * @param \XFRM\Entity\ResourceItem $resourceItem
     * @return void
     */
    protected function rumUpdateMassive(\XFRM\Entity\ResourceItem $resourceItem)
    {
        if (!$resourceItem->Category->canUploadAndManageUpdateImages()) {
            return;
        }

        $attachmentHash = $this->filter('attachment_hash', 'str');
        $this->app()
            ->jobManager()
            ->enqueueUnique(
                'rumUpdate' . $resourceItem->resource_id . substr($attachmentHash, 0, 8),
                'Truonglv\ResourceUpdateMassive:Update',
                [
                    'resourceId' => $resourceItem->resource_id,
                    'attachmentHash' => $attachmentHash
                ]
            );
    }
}
