<?php

require_once 'Swift/AbstractSwiftUnitTestCase.php';
require_once 'Swift/Mime/MimeEntity.php';
require_once 'Swift/Mime/SimpleMimeEntity.php';
require_once 'Swift/Mime/Header.php';
require_once 'Swift/Mime/ContentEncoder.php';
require_once 'Swift/Mime/FieldChangeObserver.php';
require_once 'Swift/Mime/EntityFactory.php';

Mock::generate('Swift_Mime_Header', 'Swift_Mime_MockHeader');
Mock::generate('Swift_Mime_ContentEncoder', 'Swift_Mime_MockContentEncoder');
Mock::generate(
  'Swift_Mime_FieldChangeObserver',
  'Swift_Mime_MockFieldChangeObserver'
  );
Mock::generate('Swift_Mime_MimeEntity', 'Swift_Mime_MockMimeEntity');
Mock::generate('Swift_Mime_EntityFactory', 'Swift_Mime_MockEntityFactory');

class Swift_Mime_SimpleMimeEntityTest extends Swift_AbstractSwiftUnitTestCase
{
  
  private $_encoder;
  
  public function setUp()
  {
    $this->_encoder = new Swift_Mime_MockContentEncoder();
  }
  
  public function testHeadersAreReturned()
  {
    $h = new Swift_Mime_MockHeader();
    $h->setReturnValue('getFieldName', 'Content-Type');
    $headers = array($h);
    $entity = $this->_getEntity($headers, $this->_encoder);
    $this->assertEqual($headers, $entity->getHeaders());
  }
  
  public function testHeadersAppearInString()
  {
    $h1 = new Swift_Mime_MockHeader();
    $h1->setReturnValue('getFieldName', 'Content-Type');
    $h1->setReturnValue('toString', 'Content-Type: text/html' . "\r\n");
    $h2 = new Swift_Mime_MockHeader();
    $h2->setReturnValue('getFieldName', 'X-Header');
    $h2->setReturnValue('toString', 'X-Header: foo' . "\r\n");
    $headers = array($h1, $h2);
    $entity = $this->_getEntity($headers, $this->_encoder);
    $this->assertEqual(
      'Content-Type: text/html' . "\r\n" .
      'X-Header: foo' . "\r\n",
      $entity->toString()
      );
  }
  
  public function testBodyIsAppended()
  {
    $h1 = new Swift_Mime_MockHeader();
    $h1->setReturnValue('getFieldName', 'Content-Type');
    $h1->setReturnValue('toString', 'Content-Type: text/html' . "\r\n");
    $h2 = new Swift_Mime_MockHeader();
    $h2->setReturnValue('getFieldName', 'X-Header');
    $h2->setReturnValue('toString', 'X-Header: foo' . "\r\n");
    $headers = array($h1, $h2);
    $this->_encoder->setReturnValue('encodeString', 'my body');
    $entity = $this->_getEntity($headers, $this->_encoder);
    $entity->setBodyAsString('my body');
    $this->assertEqual(
      'Content-Type: text/html' . "\r\n" .
      'X-Header: foo' . "\r\n" .
      "\r\n" .
      'my body',
      $entity->toString()
      );
  }
  
  public function testContentTypeCanBeSetAndFetched()
  {
    /* --
    This comes in very useful so Headers can observe the entity for things
    such as content-type or content-transfer-encoding changes.
    */
    
    $h = new Swift_Mime_MockHeader();
    $h->setReturnValue('getFieldName', 'Content-Type');
    $headers = array($h);
    
    $entity = $this->_getEntity($headers, $this->_encoder);
    $entity->setContentType('text/html');
    
    $this->assertEqual('text/html', $entity->getContentType());
  }
  
  public function testMimeFieldObserversAreNotifiedOnChange()
  {
    /* --
    This comes in very useful so Headers can observe the entity for things
    such as content-type or content-transfer-encoding changes.
    */
    
    $h = new Swift_Mime_MockHeader();
    $h->setReturnValue('getFieldName', 'Content-Type');
    $headers = array($h);
    
    $observer1 = new Swift_Mime_MockFieldChangeObserver();
    $observer1->expectOnce('fieldChanged', array('contenttype', 'text/html'));
    $observer2 = new Swift_Mime_MockFieldChangeObserver();
    $observer2->expectOnce('fieldChanged', array('contenttype', 'text/html'));
    
    $entity = $this->_getEntity($headers, $this->_encoder);
    $entity->registerFieldChangeObserver($observer1);
    $entity->registerFieldChangeObserver($observer2);
    
    $entity->setContentType('text/html');
  }
  
  public function testAddingChildrenGeneratesBoundary()
  {
    $h1 = new Swift_Mime_MockHeader();
    $h1->setReturnValue('getFieldName', 'Content-Type');
    $headers1 = array($h1);
    
    $observer1 = new Swift_Mime_MockFieldChangeObserver();
    //ack!
    $observer1->expectAt(1, 'fieldChanged', array('boundary', '*'));
    
    $entity1 = $this->_getEntity($headers1, $this->_encoder);
    $entity1->registerFieldChangeObserver($observer1);
    
    $h2 = new Swift_Mime_MockHeader();
    $h2->setReturnValue('getFieldName', 'Content-Type');
    $headers2 = array($h2);
    
    $entity2 = new Swift_Mime_MockMimeEntity();
    $entity2->setReturnValue('getHeaders', $headers2);
    $entity2->setReturnValue('getNestingLevel',
      Swift_Mime_MimeEntity::LEVEL_ATTACHMENT
      );
    
    $entity1->setChildren(array($entity2));
  }
  
  public function testChildrenOfLevelAttachmentOrLessGeneratesMultipartMixed()
  {
    $h1 = new Swift_Mime_MockHeader();
    $h1->setReturnValue('getFieldName', 'Content-Type');
    $headers1 = array($h1);
    
    for ($level = Swift_Mime_MimeEntity::LEVEL_ATTACHMENT;
      $level > Swift_Mime_MimeEntity::LEVEL_TOP; $level--)
    {
      $entity = $this->_getEntity($headers1, $this->_encoder);
      
      $observer = new Swift_Mime_MockFieldChangeObserver();
      $observer->expectAt(0, 'fieldChanged', array('contenttype', 'multipart/mixed'));
      $observer->expectAt(1, 'fieldChanged', array('boundary', '*'));
      $observer->expectMinimumCallCount('fieldChanged', 2);
      
      $entity->registerFieldChangeObserver($observer);
      
      $h2 = new Swift_Mime_MockHeader();
      $h2->setReturnValue('getFieldName', 'Content-Type');
      $headers2 = array($h2);
    
      $entity2 = new Swift_Mime_MockMimeEntity();
      $entity2->setReturnValue('getHeaders', $headers2);
      $entity2->setReturnValue('getNestingLevel', $level);
    
      $entity->setChildren(array($entity2));
    }
  }
  
  public function testChildrenOfLevelEmbeddedOrLessGeneratesMultipartRelated()
  {
    $h1 = new Swift_Mime_MockHeader();
    $h1->setReturnValue('getFieldName', 'Content-Type');
    $headers1 = array($h1);
    
    for ($level = Swift_Mime_MimeEntity::LEVEL_EMBEDDED;
      $level > Swift_Mime_MimeEntity::LEVEL_ATTACHMENT; $level--)
    {
      $entity = $this->_getEntity($headers1, $this->_encoder);
      
      $observer = new Swift_Mime_MockFieldChangeObserver();
      $observer->expectAt(0, 'fieldChanged', array('contenttype', 'multipart/related'));
      $observer->expectAt(1, 'fieldChanged', array('boundary', '*'));
      $observer->expectMinimumCallCount('fieldChanged', 2);
      
      $entity->registerFieldChangeObserver($observer);
      
      $h2 = new Swift_Mime_MockHeader();
      $h2->setReturnValue('getFieldName', 'Content-Type');
      $headers2 = array($h2);
    
      $entity2 = new Swift_Mime_MockMimeEntity();
      $entity2->setReturnValue('getHeaders', $headers2);
      $entity2->setReturnValue('getNestingLevel', $level);
    
      $entity->setChildren(array($entity2));
    }
  }
  
  public function testChildrenOfLevelSubpartOrLessGeneratesMultipartAlternative()
  {
    $h1 = new Swift_Mime_MockHeader();
    $h1->setReturnValue('getFieldName', 'Content-Type');
    $headers1 = array($h1);
    
    for ($level = Swift_Mime_MimeEntity::LEVEL_SUBPART;
      $level > Swift_Mime_MimeEntity::LEVEL_EMBEDDED; $level--)
    {
      $entity = $this->_getEntity($headers1, $this->_encoder);
      
      $observer = new Swift_Mime_MockFieldChangeObserver();
      $observer->expectAt(0, 'fieldChanged', array('contenttype', 'multipart/alternative'));
      $observer->expectAt(1, 'fieldChanged', array('boundary', '*'));
      $observer->expectMinimumCallCount('fieldChanged', 2);
      
      $entity->registerFieldChangeObserver($observer);
      
      $h2 = new Swift_Mime_MockHeader();
      $h2->setReturnValue('getFieldName', 'Content-Type');
      $headers2 = array($h2);
    
      $entity2 = new Swift_Mime_MockMimeEntity();
      $entity2->setReturnValue('getHeaders', $headers2);
      $entity2->setReturnValue('getNestingLevel', $level);
    
      $entity->setChildren(array($entity2));
    }
  }
  
  public function testHighestLevelChildDeterminesContentType()
  {
    $combinations  = array(
      array('levels' => array(Swift_Mime_MimeEntity::LEVEL_ATTACHMENT,
        Swift_Mime_MimeEntity::LEVEL_EMBEDDED,
        Swift_Mime_MimeEntity::LEVEL_SUBPART
        ),
        'type' => 'multipart/mixed'
        ),
      array('levels' => array(Swift_Mime_MimeEntity::LEVEL_ATTACHMENT,
        Swift_Mime_MimeEntity::LEVEL_EMBEDDED
        ),
        'type' => 'multipart/mixed'
        ),
      array('levels' => array(Swift_Mime_MimeEntity::LEVEL_ATTACHMENT,
        Swift_Mime_MimeEntity::LEVEL_SUBPART
        ),
        'type' => 'multipart/mixed'
        ),
      array('levels' => array(Swift_Mime_MimeEntity::LEVEL_EMBEDDED,
        Swift_Mime_MimeEntity::LEVEL_SUBPART
        ),
        'type' => 'multipart/related'
        )
      );
    
    foreach ($combinations as $combination)
    {
      $children = array();
      foreach ($combination['levels'] as $level)
      {
        $subentity = new Swift_Mime_MockMimeEntity();
        $subentity->setReturnValue('getNestingLevel', $level);
        $children[] = $subentity;
      }
      
      $headers = array();
      $h1 = new Swift_Mime_MockHeader();
      $h1->setReturnValue('getFieldName', 'Content-Type');
      
      $entity = $this->_getEntity($headers, $this->_encoder);
      
      $observer = new Swift_Mime_MockFieldChangeObserver();
      $observer->expectAt(0, 'fieldChanged',
        array('contenttype', $combination['type'])
        );
      $observer->expectAt(1, 'fieldChanged', array('boundary', '*'));
      
      $entity->registerFieldChangeObserver($observer);
      
      $entity->setChildren($children);
    }
  }
  
  public function testBoundaryCanBeRetrieved()
  {
    /* -- RFC 2046, 5.1.1.
     boundary := 0*69<bchars> bcharsnospace

     bchars := bcharsnospace / " "

     bcharsnospace := DIGIT / ALPHA / "'" / "(" / ")" /
                      "+" / "_" / "," / "-" / "." /
                      "/" / ":" / "=" / "?"
    */
    
    $h1 = new Swift_Mime_MockHeader();
    $h1->setReturnValue('getFieldName', 'Content-Type');
    $headers1 = array($h1);
    
    $entity1 = $this->_getEntity($headers1, $this->_encoder);
    
    $h2 = new Swift_Mime_MockHeader();
    $h2->setReturnValue('getFieldName', 'Content-Type');
    $headers2 = array($h2);
    
    $entity2 = new Swift_Mime_MockMimeEntity();
    $entity2->setReturnValue('getHeaders', $headers2);
    $entity2->setReturnValue('getNestingLevel',
      Swift_Mime_MimeEntity::LEVEL_ATTACHMENT
      );
    
    $entity1->setChildren(array($entity2));
    
    $this->assertPattern(
      '/^[a-zA-Z0-9\'\(\)\+_\-,\.\/:=\?\ ]{0,69}[a-zA-Z0-9\'\(\)\+_\-,\.\/:=\?]$/D',
      $entity1->getBoundary()
      );
  }
  
  public function testBoundaryNeverChanges()
  {
    $h1 = new Swift_Mime_MockHeader();
    $h1->setReturnValue('getFieldName', 'Content-Type');
    $headers1 = array($h1);
    
    $entity1 = $this->_getEntity($headers1, $this->_encoder);
    
    $h2 = new Swift_Mime_MockHeader();
    $h2->setReturnValue('getFieldName', 'Content-Type');
    $headers2 = array($h2);
    
    $entity2 = new Swift_Mime_MockMimeEntity();
    $entity2->setReturnValue('getHeaders', $headers2);
    $entity2->setReturnValue('getNestingLevel',
      Swift_Mime_MimeEntity::LEVEL_ATTACHMENT
      );
    
    $entity1->setChildren(array($entity2));
    
    $boundary = $entity1->getBoundary();
    for ($i = 0; $i < 10; $i++)
    {
      $this->assertEqual($boundary, $entity1->getBoundary());
    }
  }
  
  public function testBoundaryCanBeManuallySet()
  {
    $h1 = new Swift_Mime_MockHeader();
    $h1->setReturnValue('getFieldName', 'Content-Type');
    $headers1 = array($h1);
    
    $entity1 = $this->_getEntity($headers1, $this->_encoder);
    
    $h2 = new Swift_Mime_MockHeader();
    $h2->setReturnValue('getFieldName', 'Content-Type');
    $headers2 = array($h2);
    
    $entity2 = new Swift_Mime_MockMimeEntity();
    $entity2->setReturnValue('getHeaders', $headers2);
    $entity2->setReturnValue('getNestingLevel',
      Swift_Mime_MimeEntity::LEVEL_ATTACHMENT
      );
      
    $entity1->setBoundary('my_boundary');
    
    $entity1->setChildren(array($entity2));
    
    $this->assertEqual('my_boundary', $entity1->getBoundary());
  }
  
  public function testChildrenAppearInString()
  {
    /* -- RFC 2046, 5.1.1.
     (excerpt too verbose to paste here)
     */
    
    $h1 = new Swift_Mime_MockHeader();
    $h1->setReturnValue('getFieldName', 'Content-Type');
    $h1->setReturnValue('toString',
      'Content-Type: multipart/alternative;' . "\r\n" .
      ' boundary="_=_foo_=_"' . "\r\n"
      );
    $headers1 = array($h1);
    
    $entity1 = $this->_getEntity($headers1, $this->_encoder);
    $entity1->setBoundary('_=_foo_=_');
    
    $entity2 = new Swift_Mime_MockMimeEntity();
    $entity2->setReturnValue('getNestingLevel',
      Swift_Mime_MimeEntity::LEVEL_SUBPART
      );
    $entity2->setReturnValue('toString',
      'Content-Type: text/plain' . "\r\n" .
      "\r\n" .
      'foobar test'
      );
    
    $entity3 = new Swift_Mime_MockMimeEntity();
    $entity3->setReturnValue('getNestingLevel',
      Swift_Mime_MimeEntity::LEVEL_SUBPART
      );
    $entity3->setReturnValue('toString',
      'Content-Type: text/html' . "\r\n" .
      "\r\n" .
      'foobar <strong>test</strong>'
      );
    
    $entity1->setChildren(array($entity2, $entity3));
    
    $this->assertEqual(
      'Content-Type: multipart/alternative;' . "\r\n" .
      ' boundary="_=_foo_=_"' . "\r\n" .
      "\r\n" .
      '--_=_foo_=_' . "\r\n" .
      'Content-Type: text/plain' . "\r\n" .
      "\r\n" .
      'foobar test' . "\r\n" .
      '--_=_foo_=_' . "\r\n" .
      'Content-Type: text/html' . "\r\n" .
      "\r\n" .
      'foobar <strong>test</strong>' . "\r\n" .
      '--_=_foo_=_--' . "\r\n"
      ,
      $entity1->toString()
      );
  }
  
  public function testMixingLevelsIsHierarchical()
  {
    $h1 = new Swift_Mime_MockHeader();
    $h1->setReturnValue('getFieldName', 'Content-Type');
    $h1->setReturnValue('toString',
      'Content-Type: multipart/mixed;' . "\r\n" .
      ' boundary="_=_foo_=_"' . "\r\n"
      );
    $headers = array($h1);
    $entity1 = $this->_getEntity($headers, $this->_encoder);
    $entity1->setBoundary('_=_foo_=_');
    
    //Create some entities which nest differently
    $entity2 = new Swift_Mime_MockMimeEntity();
    $entity2->setReturnValue('getNestingLevel',
      Swift_Mime_MimeEntity::LEVEL_ATTACHMENT
      );
    $entity2->setReturnValue('toString',
      'Content-Type: application/octet-stream' . "\r\n" .
      "\r\n" .
      'foo'
      );
    
    $entity3 = new Swift_Mime_MockMimeEntity();
    $entity3->setReturnValue('getNestingLevel',
      Swift_Mime_MimeEntity::LEVEL_SUBPART
      );
    $entity3->setReturnValue('toString',
      'Content-Type: text/plain' . "\r\n" .
      "\r\n" .
      'xyz'
      );
    
    //Mock out a factory which returns a mock entity
    $emptyEntity = new Swift_Mime_MockMimeEntity();
    $emptyEntity->expectOnce('setNestingLevel',
      array(Swift_Mime_MimeEntity::LEVEL_ATTACHMENT)
      );
    $emptyEntity->expectOnce('setChildren', array(array($entity3)));
    $emptyEntity->setReturnValue('toString',
      'Content-Type: multipart/alternative;' . "\r\n" .
      ' boundary="_=_bar_=_"' . "\r\n" .
      "\r\n" .
      '--_=_bar_=_' . "\r\n" .
      'Content-Type: text/plain' . "\r\n" .
      "\r\n" .
      'xyz' . "\r\n" .
      '--_=_bar_=_--' . "\r\n"
      );
    
    $factory = new Swift_Mime_MockEntityFactory();
    $factory->setReturnValue('createBaseEntity', $emptyEntity);
    
    //Apply the mock factory
    $entity1->setEntityFactory($factory);
    
    $entity1->setChildren(array($entity2, $entity3));
    
    $stringEntity = $entity1->toString();
    
    $this->assertEqual(
      'Content-Type: multipart/mixed;' . "\r\n" .
      ' boundary="_=_foo_=_"' . "\r\n" .
      "\r\n" .
      '--_=_foo_=_' . "\r\n" .
      'Content-Type: multipart/alternative;' . "\r\n" .
      ' boundary="_=_bar_=_"' . "\r\n" .
      "\r\n" .
      '--_=_bar_=_' . "\r\n" .
      'Content-Type: text/plain' . "\r\n" .
      "\r\n" .
      'xyz' . "\r\n" .
      '--_=_bar_=_--' . "\r\n" .
      "\r\n" .
      '--_=_foo_=_' . "\r\n" .
      'Content-Type: application/octet-stream' . "\r\n" .
      "\r\n" .
      'foo' .
      "\r\n" .
      '--_=_foo_=_--' . "\r\n",
      $stringEntity
      );
  }
  
  public function testSettingEncoderNotifiesFieldChange()
  {
    $this->_encoder->setReturnValue('getName', 'quoted-printable');
    
    $h = new Swift_Mime_MockHeader();
    $h->setReturnValue('getFieldName', 'Content-Type');
    $headers = array($h);
    
    $encoder = new Swift_Mime_MockContentEncoder();
    $encoder->setReturnValue('getName', 'base64');
    
    $observer1 = new Swift_Mime_MockFieldChangeObserver();
    $observer1->expectOnce('fieldChanged',
      array('contenttransferencoding', 'base64')
      );
    $observer2 = new Swift_Mime_MockFieldChangeObserver();
    $observer2->expectOnce('fieldChanged',
      array('contenttransferencoding', 'base64')
      );
    
    $entity = $this->_getEntity($headers, $this->_encoder);
    
    $entity->registerFieldChangeObserver($observer1);
    $entity->registerFieldChangeObserver($observer2);
    
    $entity->setEncoder($encoder);
  }
  
  public function testEncoderIsUsedForStringGeneration()
  {
    $h1 = new Swift_Mime_MockHeader();
    $h1->setReturnValue('getFieldName', 'Content-Type');
    $h1->setReturnValue('toString', 'Content-Type: text/html' . "\r\n");
    $h2 = new Swift_Mime_MockHeader();
    $h2->setReturnValue('getFieldName', 'X-Header');
    $h2->setReturnValue('toString', 'X-Header: foo' . "\r\n");
    $headers = array($h1, $h2);
    $this->_encoder->expectOnce('encodeString', array('my body', '*', '*'));
    $this->_encoder->setReturnValue('encodeString', 'my body');
    $entity = $this->_getEntity($headers, $this->_encoder);
    $entity->setBodyAsString('my body');
    $this->assertEqual(
      'Content-Type: text/html' . "\r\n" .
      'X-Header: foo' . "\r\n" .
      "\r\n" .
      'my body',
      $entity->toString()
      );
  }
  
  public function testMaxLineLengthIsProvidedForEncoding()
  {
    $h1 = new Swift_Mime_MockHeader();
    $h1->setReturnValue('getFieldName', 'Content-Type');
    $h1->setReturnValue('toString', 'Content-Type: text/html' . "\r\n");
    $h2 = new Swift_Mime_MockHeader();
    $h2->setReturnValue('getFieldName', 'X-Header');
    $h2->setReturnValue('toString', 'X-Header: foo' . "\r\n");
    $headers = array($h1, $h2);
    
    $this->_encoder->expectOnce('encodeString', array('my body', 0, 78));
    $this->_encoder->setReturnValue('encodeString', 'my body');
    
    $entity = $this->_getEntity($headers, $this->_encoder);
    $entity->setMaxLineLength(78);
    $entity->setBodyAsString('my body');
    
    $entity->toString();
  }
  
  // -- Private helpers
  
  private function _getEntity($headers, $encoder)
  {
    return new Swift_Mime_SimpleMimeEntity($headers, $encoder);
  }
  
}
