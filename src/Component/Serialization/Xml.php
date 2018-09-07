<?php

namespace Drupal\webform_rest_handler\Component\Serialization;

use Drupal\Component\Serialization\SerializationInterface;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

/**
 * Default serialization for JSON.
 *
 * @ingroup third_party
 */
class Xml implements SerializationInterface {

  /**
   * {@inheritdoc}
   *
   * Convert PHP array into an XML doc.
   */
  public static function encode($variable, $options = []) {

    $defaults = ['xml_version' => '1.0', 'xml_encoding' => 'UTF-8', 'xml_standalone' => TRUE];

    $encoder = new XmlEncoder();
    return $encoder->encode($variable, 'xml', $options + $defaults);
  }

  /**
   * {@inheritdoc}
   */
  public static function decode($xml) {
    $encoder = new XmlEncoder();
    return $encoder->decode($xml, 'xml', ['xml_root_node_name']);
  }

  /**
   * {@inheritdoc}
   */
  public static function getFileExtension() {
    return 'xml';
  }

}
