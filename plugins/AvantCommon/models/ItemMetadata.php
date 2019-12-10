<?php

class ItemMetadata
{
    const SHARED_DATA_SIGNATURE = 'digitalarchive';

    public static function emitSharedItemAssets($item)
    {
        // Return this sharable item's assets, namely the URL for its thumbnail and image.
        $info = array();
        $info['signature'] = self::SHARED_DATA_SIGNATURE;
        $info['contributor'] = get_option('site_title');

        $itemFiles = $item->Files;
        if (count($itemFiles) > 0)
        {
            $file = $itemFiles[0];
            $info['thumbnail'] = $file->getWebPath('thumbnail');
            $info['image'] = $file->getWebPath('original');
        }

        return json_encode($info);
    }

    public static function getAllElementTextsForElementName($item, $elementName)
    {
        $elementSetName = self::getElementSetNameForElementName($elementName);
        $elementTexts = $item->getElementTexts($elementSetName, $elementName);
        $texts = array();
        foreach ($elementTexts as $elementText)
        {
            $texts[] = $elementText->getText();
        }
        return $texts;
    }

    public static function getElementIdForElementName($elementName)
    {
        $db = get_db();
        $elementTable = $db->getTable('Element');
        $element = $elementTable->findByElementSetNameAndElementName('Dublin Core', $elementName);
        if (empty($element))
            $element = $elementTable->findByElementSetNameAndElementName('Item Type Metadata', $elementName);
        return empty($element) ? 0 : $element->id;
    }

    public static function getElementNameFromId($elementId)
    {
        $db = get_db();
        $element = $db->getTable('Element')->find($elementId);
        return isset($element) ? $element->name : '';
    }

    public static function getElementSetNameForElementName($elementName)
    {
        $db = get_db();
        $elementTable = $db->getTable('Element');

        $elementSetName = 'Dublin Core';
        $element = $elementTable->findByElementSetNameAndElementName($elementSetName, $elementName);
        if (empty($element))
        {
            $elementSetName = 'Item Type Metadata';
            $element = $elementTable->findByElementSetNameAndElementName($elementSetName, $elementName);
        }
        return empty($element) ? '' : $elementSetName;
    }

    public static function getElementTextsByValue($elementId, $value)
    {
        $results = array();

        if (!empty($value))
        {
            $db = get_db();
            $select = $db->select()
                ->from($db->ElementText)
                ->where('element_id = ?', $elementId)
                ->where('text = ?', $value)
                ->where('record_type = ?', 'Item');
            $results = $db->getTable('ElementText')->fetchObjects($select);
        }

        return $results;
    }

    public static function getElementTextForElementName($item, $elementName, $asHtml = true)
    {
        try
        {
            $elementSetName = self::getElementSetNameForElementName($elementName);
            $text = metadata($item, array($elementSetName, $elementName), array('no_filter' => true, 'no_escape' => !$asHtml));
        }
        catch (Omeka_Record_Exception $e)
        {
            $text = '';
        }
        return $text;
    }

    public static function getElementTextFromElementId($item, $elementId, $asHtml = true)
    {
        $db = get_db();
        $element = $db->getTable('Element')->find($elementId);
        $text = '';
        if (!empty($element))
        {
            $texts = $item->getElementTextsByRecord($element);
            $text = isset($texts[0]['text']) ? $texts[0]['text'] : '';
        }
        return $asHtml ? html_escape($text) : $text;
    }

    public static function getIdentifierAliasElementName()
    {
        $elementName = CommonConfig::getOptionTextForIdentifierAlias();
        if (empty($elementName))
            $elementName = ItemMetadata::getIdentifierElementName();
        return $elementName;
    }

    public static function getIdentifierElementName()
    {
        return CommonConfig::getOptionTextForIdentifier();
    }

    public static function getIdentifierElementId()
    {
        return self::getElementIdForElementName(self::getIdentifierElementName());
    }

    public static function getIdentifierPrefix()
    {
        return CommonConfig::getOptionTextForIdentifierPrefix();
    }

    public static function getItemFromId($id)
    {
        return get_record_by_id('Item', $id);
    }

    public static function getItemFromIdentifier($identifier)
    {
        $elementId = CommonConfig::getOptionDataForIdentifier();
        $items = get_records('Item', array('advanced' => array(array('element_id' => $elementId, 'type' => 'is exactly', 'terms' => $identifier))));
        if (empty($items))
            return null;
        return $items[0];
    }

    public static function getItemIdentifier($item)
    {
        return self::getElementTextFromElementId($item, CommonConfig::getOptionDataForIdentifier());
    }

    public static function getItemIdentifierAlias($item)
    {
        $aliasElementId = CommonConfig::getOptionDataForIdentifierAlias();
        if (empty($aliasElementId))
            $aliasText = self::getItemIdentifier($item);
        else
            $aliasText = self::getElementTextFromElementId($item, $aliasElementId);
        return $aliasText;
    }

    public static function getItemIdFromIdentifier($identifier)
    {
        $item = self::getItemFromIdentifier($identifier);
        return empty($item) ? 0 : $item->id;
    }

    public static function getItemsWithElementValue($elementId, $value)
    {
        $sql = ItemSearch::fetchItemsWithElementValue($elementId, $value);
        $db = get_db();
        $results = $db->query($sql)->fetchAll();
        return $results;
    }

    public static function getItemTitle($item, $asHtml = true)
    {
        return self::getElementTextFromElementId($item, self::getTitleElementId(), $asHtml);
    }

    public static function getSharedItemAssets($item)
    {
        $assets = array();
        $sharedItemElementId = json_decode(get_option('avantcommon_shared_item'), true);
        if (intval($sharedItemElementId) == 0 )
        {
            // This site does not include items shared from other sites.
            return $assets;
        }

        $sharedItemElementText = ItemMetadata::getElementTextFromElementId($item, $sharedItemElementId, false);

        if (empty($sharedItemElementText))
        {
            // This item is not shared from another site.
            return $assets;
        }

        $parts = array_map('trim', explode(PHP_EOL, $sharedItemElementText));
        $partsCount = count($parts);
        if ($partsCount == 1)
        {
            // The shared element value is a single line of text, presumed to be the URL for a puglic Digital Archive item.

            $url = $parts[0];
            $data = self::requestAssetsFromSharedItem("$url?share=");

            if (isset($data['error']))
            {
                // The request to the URL either failed or did not return proper data.
                $assets['error'] = true;
                //$assets['response-code'] = $data['response-code'];
                $assets['response-code'] = '[' . json_encode($data) . ']';
            }
            else
            {
                // The data appears to be good, but verify the signature to make sure it's what's expected.
                $assets = json_decode($data, true);
                $signature = isset($assets['signature']) ? $assets['signature'] : '';

                if ($signature != self::SHARED_DATA_SIGNATURE)
                {
                    // The data that came back is not what was expected.
                    $assets['error'] = true;
                }
            }

            // Insert the URL from which the data came.
            $assets['item-url'] = $url;
        }
        else if ($partsCount == 2 || $partsCount >= 4)
        {
            // This item's metadata is shared from another site.
            $assets['item-url'] = $parts[0];
            $assets['contributor'] = $parts[1];

            if ($partsCount >= 4)
            {
                // This item's thumbnail and image are shared from another site.
                $assets['thumbnail'] = $parts[2];
                $assets['image'] = $parts[3];
            }
        }
        else
        {
            $assets['error'] = true;
            $assets['response-code'] = '[' . $partsCount . ']';
        }

        return $assets;
    }

    public static function getTitleElementId()
    {
        return self::getElementIdForElementName(self::getTitleElementName());
    }

    public static function getTitleElementName()
    {
        return 'Title';
    }

    protected static function requestAssetsFromSharedItem($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE );
        $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (empty($data) || $contentType != 'application/json')
        {
            $data = array();
            $data['error'] = true;
            $data['response-code'] = $responseCode;
        }

        return $data;
    }

    public static function updateElementText($item, $elementId, $text)
    {
        $element = $item->getElementById($elementId);

        // Remove the old value(s) for this element.
        $elementTexts = $item->getElementTextsByRecord($element);
        foreach ($elementTexts as $elementText)
        {
            $elementText->delete();
        }

        // Add the new value.
        if (strlen($text) != 0)
        {
            $item->addTextForElement($element, $text);
            $item->saveElementTexts();
        }
    }
}