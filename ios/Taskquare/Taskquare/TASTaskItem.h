//
//  TASTaskItem.h
//  Taskquare
//
//  Created by Vin Gardner on 10/11/2013.
//  Copyright (c) 2013 Vin Gardner. All rights reserved.
//

#import <Foundation/Foundation.h>

@interface TASTaskItem : NSObject

@property NSString *itemName;
@property BOOL completed;
@property (readonly) NSDate *creationDate;

@end
