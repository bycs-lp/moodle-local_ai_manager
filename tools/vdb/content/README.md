# Content classes

These classes provide a mechanism for an input to be transformed in to suitable representation to then be embedded and stored in a vector database.

Note: these functions should not perform the text embedding step, this is just about converting the content from (say) an activity.

# Example: Lesson

The Lesson activity allows for a pathway through the learning, as opposed to a singluar route (like a book or page). This function would understand the lesson's structure, and convert it into a representation that can be held as "knowledge". 

This could be to represent each "page" as a "container", with statements being generated to indicate how each container links to another "container", mirroring the choices that the user could make.

A pre-amble that indicates the activity's content (potentially) represents a user-defined navigation path may be able to hint to the AI how to best to use the knowledge contained.