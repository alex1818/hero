<?php

/**
* Content Model
*
* Manages content
*
* @author Electric Function, Inc.
* @package Electric Publisher

*/

class Content_model extends CI_Model
{
	function __construct()
	{
		parent::CI_Model();
	}
	
	/*
	* Create New Content
	*
	* @param int $type
	* @param int $user The ID of the submitting user
	* @param string $title
	* @param string $url_path
	* @param array $topics Array of topic ID's
	* @param array $privileges Array of membergroup ID's to restrict access to
	* @param array $custom_fields Generated by custom_fields_model->post_to_array()
	*
	* @return $content_id
	*/
	function new_content ($type, $user, $title = '', $url_path = '', $topics = array(), $privileges = array(), $custom_fields = array()) {
		$this->load->model('publish/content_type_model');
		$type = $this->content_type_model->get_content_type($type);
		
		if (empty($type)) {
			return FALSE;
		}
		
		$this->load->helper('clean_string');
		$url_path = (empty($url_path)) ? clean_string($title) : clean_string($url_path);
		
		// get a global link ID
		// make sure URL is unique
		$this->load->model('link_model');
		$url_path = $this->link_model->get_unique_url_path($url_path);
		
		$link_id = $this->link_model->new_link($url_path, $topics, $title, $type['singular_name'], 'publish', 'content', 'view');
		
		// insert it into standard content table first
		$insert_fields = array(
							'link_id' => $link_id,
							'content_type_id' => $type['id'],
							'content_is_standard' => (empty($title)) ? '0' : '1',
							'content_title' => $title,
							'content_privileges' => (is_array($privileges) and !in_array(0, $privileges)) ? serialize($privileges) : '',
							'content_date' => date('Y-m-d H:i:s'),
							'content_modified' => date('Y-m-d H:i:s'),
							'user_id' => $user,
							'content_topics' => (is_array($topics) and !empty($topics)) ? serialize($topics) : ''
						);
						
		$this->db->insert('content',$insert_fields);
		$content_id = $this->db->insert_id();
						
		// map to topics
		foreach ($topics as $topic) {
			if ($topic != '0') {
				$this->db->insert('topic_maps',array('topic_id' => $topic, 'content_id' => $content_id));
			}
		}
		
		// insert it into its own content table
		$insert_fields = array(
							'content_id' => $content_id
						);
						
		if (is_array($custom_fields)) {					
			foreach ($custom_fields as $name => $value) {
				$insert_fields[$name] = $value;
			}
		}
		
		$this->db->insert($type['system_name'], $insert_fields);
		
		return $content_id;
	}
	
	/*
	* Update Content
	*
	* @param int $content_id
	* @param string $title
	* @param string $url_path
	* @param array $topics Array of topic ID's
	* @param array $privileges Array of membergroup ID's to restrict access to
	* @param array $custom_fields Generated by custom_fields_model->post_to_array()
	*
	* @return $content_id
	*/
	function update_content ($content_id, $title = '', $url_path = '', $topics = array(), $privileges = array(), $custom_fields = array()) {
		$content = $this->get_content($content_id);
	
		$this->load->model('publish/content_type_model');
		$type = $this->content_type_model->get_content_type($content['type_id']);
		
		if (empty($url_path)) {
			$this->load->helper('clean_string');
			$url_path = clean_string($title);
		}
		
		// make sure URL is unique (unless it hasn't changed, of course)
		$this->load->model('link_model');
		if ($content['url_path'] != $url_path) {
			$url_path = $this->link_model->get_unique_url_path($url_path);
			$this->link_model->update_url($content['link_id'], $url_path);
		}
		$this->link_model->update_title($content['link_id'], $title);
		$this->link_model->update_topics($content['link_id'], $topics);
		
		// update standard content table first
		$update_fields = array(
							'content_title' => $title,
							'content_privileges' => (is_array($privileges) and !in_array(0, $privileges)) ? serialize($privileges) : '',
							'content_modified' => date('Y-m-d H:i:s'),
							'content_topics' => (is_array($topics) and !empty($topics)) ? serialize($topics) : ''
						);
						
		$this->db->update('content',$update_fields,array('content_id' => $content['id']));
						
		// clear topic maps
		$this->db->delete('topic_maps',array('content_id' => $content['id']));
						
		// map to topics
		foreach ($topics as $topic) {
			if ($topic != '0') {
				$this->db->insert('topic_maps',array('topic_id' => $topic, 'content_id' => $content['id']));
			}
		}
		
		// update its own content table
		$update_fields = array();
						
		if (is_array($custom_fields)) {					
			foreach ($custom_fields as $name => $value) {
				$update_fields[$name] = $value;
			}
		}
		
		$this->db->update($type['system_name'], $update_fields, array('content_id' => $content['id']));
		
		return TRUE;
	}

	/*
	* Delete Content
	*
	* @param int $content_id
	*
	* @return boolean TRUE
	*/
	function delete_content ($content_id) {
		$content = $this->get_content($content_id);
		
		$this->load->model('publish/content_type_model');
		$type = $this->content_type_model->get_content_type($content['type_id']);
		
		if (empty($content)) {
			return FALSE;
		}
		
		$this->db->delete('content',array('content_id' => $content_id));
		$this->db->delete('links',array('link_id' => $content['link_id']));
		$this->db->delete($type['system_name'], array('content_id' => $content_id));
		
		return TRUE;
	}
	
	/*
	* Get Content
	*
	* Gets a single piece of content, full data
	*
	* @param int $content_id
	*
	* @return array $content
	*/
	function get_content ($content_id) {
		$content = $this->get_contents(array('id' => $content_id));
		
		if (empty($content)) {
			return FALSE;
		}
		
		return $content[0];
	}
	
	/*
	* Get Contents
	*
	* Gets content by filters
	* If an ID or Type ID is present in filters, it will retrieve all content data from the specific content table
	*
	* @param date $filters['start_date'] Only content after this date
	* @param date $filters['end_date'] Only content before this date
	* @param string $filters['author_like'] Only content created by this user (by username, text search)
	* @param int $filters['type'] Only content of this type
	* @param int $filters['id']
	* @param int $filters['topic']
	*
	* @return array|boolean Array of content, or FALSE
	*/
	function get_contents ($filters = array()) {
		// do we need to get all content data?  i.e., does it make resource saving sense?
		if (isset($filters['id']) or isset($filters['type'])) {
			// find out the table name
			$this->db->select('content_type_id');
			if (isset($filters['id'])) {
				$this->db->where('content_id',$filters['id']);
			}
			elseif (isset($filters['type'])) {
				$this->db->where('content_type_id',$filters['type']);
			}
			
			$result = $this->db->get('content');
			
			if ($result->num_rows() == 0) {
				return FALSE;
			}
			else {
				$row = $result->row_array();
				
				$this->load->model('publish/content_type_model');
				$type = $this->content_type_model->get_content_type($row['content_type_id']);
				
				// get custom fields
				$this->load->model('custom_fields_model');
				$custom_fields = $this->custom_fields_model->get_custom_fields(array('group' => $type['custom_field_group_id']));
				
				// join this table into the mix
				$this->db->join($type['system_name'], 'content.content_id = ' . $type['system_name'] . '.content_id','left');
			}
		}
	
		if (isset($filters['start_date'])) {
			$start_date = date('Y-m-d H:i:s', strtotime($filters['start_date']));
			$this->db->where('content.content_date >=', $start_date);
		}
		
		if (isset($filters['end_date'])) {
			$end_date = date('Y-m-d H:i:s', strtotime($filters['end_date']));
			$this->db->where('content.content_date <=', $end_date);
		}
		
		if (isset($filters['author_like'])) {
			$this->db->like('users.user_username',$filters['author_like']);
		}
		
		if (isset($filters['type'])) {
			$this->db->where('content.content_type_id',$filters['type']);
		}
		
		if (isset($filters['id'])) {
			$this->db->where('content.content_id',$filters['id']);
		}
		
		if (isset($filters['is_standard'])) {
			$this->db->where('content.content_is_standard',$filters['is_standard']);
		}
		
		if (isset($filters['topic'])) {
			$this->db->join('topic_maps','topic_maps.content_id = content.content_id','left');
			$this->db->where('topic_maps.topic_id',$filters['topic']);
		}
		
		// standard ordering and limiting
		$order_by = (isset($filters['sort'])) ? $filters['sort'] : 'content.content_date';
		$order_dir = (isset($filters['sort_dir'])) ? $filters['sort_dir'] : 'DESC';
		$this->db->order_by($order_by, $order_dir);
		
		if (isset($filters['limit'])) {
			$offset = (isset($filters['offset'])) ? $filters['offset'] : 0;
			$this->db->limit($filters['limit'], $offset);
		}
		
		$this->db->join('users','users.user_id = content.user_id','left');
		$this->db->join('content_types','content_types.content_type_id = content.content_type_id','left');
		$this->db->join('links','links.link_id = content.link_id','left');
		
		$result = $this->db->get('content');
		
		if ($result->num_rows() == 0) {
			return FALSE;
		}
		
		$contents = array();
		foreach ($result->result_array() as $content) {
			$this_content = array(
								'id' => $content['content_id'],
								'link_id' => $content['link_id'],
								'date' => $content['content_date'],
								'modified_date' => $content['content_modified'],
								'author_id' => $content['user_id'],
								'author_username' => $content['user_username'],
								'author_first_name' => $content['user_first_name'],
								'author_last_name' => $content['user_last_name'],
								'author_email' => $content['user_email'],
								'type_id' => $content['content_type_id'],
								'type_name' => $content['content_type_friendly_name'],
								'is_standard' => ($content['content_is_standard'] == '1') ? TRUE : FALSE,
								'title' => ($content['content_is_standard'] == '1') ? $content['content_title'] : 'Entry #' . $content['content_id'],
								'url_path' => $content['link_url_path'],
								'url' => site_url($content['link_url_path']),
								'privileges' => (!empty($content['content_privileges'])) ? unserialize($content['content_privileges']) : FALSE,
								'topics' => (!empty($content['content_topics'])) ? unserialize($content['content_topics']) : FALSE
							);
							
			// are we loading in all content data?
			if (isset($custom_fields) and !empty($custom_fields)) {
				foreach ($custom_fields as $field) {
					$this_content[$field['name']] = $content[$field['name']];
				}
				reset($custom_fields);
			}
			
			$contents[] = $this_content;
		}
		
		return $contents;
	}
}	